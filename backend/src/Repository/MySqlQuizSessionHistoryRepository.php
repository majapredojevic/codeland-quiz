<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\DTO\QuizSessionHistoryQueryDTO;
use CodeLandQuiz\Model\QuizSessionHistoryOverview;
use CodeLandQuiz\Model\QuizSessionHistorySort;
use CodeLandQuiz\Model\QuizSessionStatus;
use CodeLandQuiz\Model\QuizSessionStatusFilter;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use PDO;
use PDOStatement;

final readonly class MySqlQuizSessionHistoryRepository implements QuizSessionHistoryRepository
{
    private const SELECT_OVERVIEW_SQL = <<<SQL
SELECT
    qs.id,
    qs.quiz_id,
    qs.quiz_title,
    qs.quiz_version,
    qs.host_user_id,
    host.name AS host_user_name,
    qs.game_pin,
    qs.status,
    (
        SELECT COUNT(*)
        FROM session_questions sq
        WHERE sq.session_id = qs.id
    ) AS question_count,
    (
        SELECT COUNT(*)
        FROM session_participants sp
        WHERE sp.session_id = qs.id
          AND sp.is_removed = FALSE
    ) AS participant_count,
    (
        SELECT COUNT(*)
        FROM session_participants sp
        WHERE sp.session_id = qs.id
          AND sp.is_removed = TRUE
    ) AS removed_participant_count,
    qs.started_at,
    qs.ended_at,
    qs.created_at
FROM quiz_sessions qs
INNER JOIN users host
    ON host.id = qs.host_user_id
SQL;

    private const COUNT_SQL = <<<SQL
SELECT COUNT(*)
FROM quiz_sessions qs
INNER JOIN users host
    ON host.id = qs.host_user_id
SQL;

    public function __construct(
        private Database $database,
    ) {
    }

    /**
     * @return QuizSessionHistoryOverview[]
     */
    public function findPage(QuizSessionHistoryQueryDTO $query): array
    {
        $sql = self::SELECT_OVERVIEW_SQL
            . $this->searchJoinClause($query->search)
            . $this->whereClause($query)
            . "\n"
            . $this->orderByClause($query->sort)
            . "\nLIMIT :limit\nOFFSET :offset";
        $statement = $this->connection()->prepare($sql);

        $this->bindFilters($statement, $query);
        $statement->bindValue(':limit', $query->pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', $query->getOffset(), PDO::PARAM_INT);
        $statement->execute();

        $sessions = [];

        while (($row = $statement->fetch()) !== false) {
            $sessions[] = $this->mapRowToOverview($row);
        }

        return $sessions;
    }

    public function count(QuizSessionHistoryQueryDTO $query): int
    {
        $sql = self::COUNT_SQL
            . $this->searchJoinClause($query->search)
            . $this->whereClause($query);
        $statement = $this->connection()->prepare($sql);

        $this->bindFilters($statement, $query);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    private function searchJoinClause(?string $search): string
    {
        if ($search === null) {
            return '';
        }

        return "\nCROSS JOIN (SELECT :search AS search_value) session_search";
    }

    private function whereClause(QuizSessionHistoryQueryDTO $query): string
    {
        $clause = "\nWHERE 1 = 1";

        if ($query->status !== QuizSessionStatusFilter::ALL) {
            $clause .= "\n  AND qs.status = :status";
        }

        if ($query->quizId !== null) {
            $clause .= "\n  AND qs.quiz_id = :quiz_id";
        }

        if ($query->search !== null) {
            $clause .= "\n  AND (\n"
                . "      qs.quiz_title LIKE session_search.search_value ESCAPE '\\\\'\n"
                . "      OR qs.game_pin LIKE session_search.search_value ESCAPE '\\\\'\n"
                . "      OR host.name LIKE session_search.search_value ESCAPE '\\\\'\n"
                . '  )';
        }

        return $clause;
    }

    private function orderByClause(QuizSessionHistorySort $sort): string
    {
        return match ($sort) {
            QuizSessionHistorySort::RECENT =>
                'ORDER BY qs.created_at DESC, qs.id DESC',
            QuizSessionHistorySort::OLDEST =>
                'ORDER BY qs.created_at ASC, qs.id ASC',
            QuizSessionHistorySort::QUIZ_TITLE_ASC =>
                'ORDER BY qs.quiz_title ASC, qs.quiz_version ASC, qs.id ASC',
            QuizSessionHistorySort::QUIZ_TITLE_DESC =>
                'ORDER BY qs.quiz_title DESC, qs.quiz_version DESC, qs.id DESC',
        };
    }

    private function bindFilters(
        PDOStatement $statement,
        QuizSessionHistoryQueryDTO $query,
    ): void {
        if ($query->search !== null) {
            $statement->bindValue(
                ':search',
                '%' . $this->escapeLikePattern($query->search) . '%',
            );
        }

        if ($query->status !== QuizSessionStatusFilter::ALL) {
            $statement->bindValue(':status', $query->status->value);
        }

        if ($query->quizId !== null) {
            $statement->bindValue(':quiz_id', $query->quizId, PDO::PARAM_INT);
        }
    }

    private function escapeLikePattern(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToOverview(
        array $row,
    ): QuizSessionHistoryOverview {
        return new QuizSessionHistoryOverview(
            id: (int) $row['id'],
            quizId: (int) $row['quiz_id'],
            quizTitle: (string) $row['quiz_title'],
            quizVersion: (int) $row['quiz_version'],
            hostUserId: (int) $row['host_user_id'],
            hostUserName: (string) $row['host_user_name'],
            gamePin: (string) $row['game_pin'],
            status: QuizSessionStatus::from((string) $row['status']),
            questionCount: (int) $row['question_count'],
            participantCount: (int) $row['participant_count'],
            removedParticipantCount: (int) $row['removed_participant_count'],
            startedAt: $this->nullableDateTime($row['started_at']),
            endedAt: $this->nullableDateTime($row['ended_at']),
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }

    private function nullableDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return new DateTimeImmutable((string) $value);
    }

    private function connection(): PDO
    {
        return $this->database->getConnection();
    }
}
