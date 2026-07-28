<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\QuizOverview;
use CodeLandQuiz\Model\QuizSort;
use CodeLandQuiz\Model\QuizStatusFilter;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use PDO;
use PDOStatement;

final readonly class MySqlQuizRepository implements QuizRepository
{
    private const SELECT_OVERVIEW_SQL = <<<SQL
SELECT
    q.id,
    q.title,
    q.version,
    q.description,
    q.is_active,
    q.topic_id,
    t.name AS topic_name,
    q.created_by,
    creator.name AS created_by_name,
    q.updated_by,
    updater.name AS updated_by_name,
    q.created_at,
    q.updated_at,
    (
        SELECT COUNT(*)
        FROM questions question_count
        WHERE question_count.quiz_id = q.id
          AND question_count.is_deleted = FALSE
    ) AS question_count
FROM quizzes q
LEFT JOIN topics t
    ON t.id = q.topic_id
INNER JOIN users creator
    ON creator.id = q.created_by
INNER JOIN users updater
    ON updater.id = q.updated_by
WHERE q.is_deleted = FALSE
SQL;

    private const COUNT_SQL = <<<SQL
SELECT COUNT(*)
FROM quizzes q
WHERE q.is_deleted = FALSE
SQL;

    public function __construct(
        private Database $database,
    ) {
    }

    /**
     * @return QuizOverview[]
     */
    public function findPage(
        int $limit,
        int $offset,
        ?string $search,
        ?int $topicId,
        QuizStatusFilter $status,
        QuizSort $sort,
    ): array {
        $sql = self::SELECT_OVERVIEW_SQL
            . $this->filterClause($search, $topicId, $status)
            . "\n"
            . $this->orderByClause($sort)
            . "\nLIMIT :limit\nOFFSET :offset";
        $statement = $this->connection()->prepare($sql);

        $this->bindFilters($statement, $search, $topicId);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $quizzes = [];

        while (($row = $statement->fetch()) !== false) {
            $quizzes[] = $this->mapRowToQuizOverview($row);
        }

        return $quizzes;
    }

    public function count(
        ?string $search,
        ?int $topicId,
        QuizStatusFilter $status,
    ): int {
        $sql = self::COUNT_SQL . $this->filterClause($search, $topicId, $status);
        $statement = $this->connection()->prepare($sql);

        $this->bindFilters($statement, $search, $topicId);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function findOverviewById(int $id): ?QuizOverview
    {
        $sql = self::SELECT_OVERVIEW_SQL . "\nAND q.id = :id\nLIMIT 1";
        $statement = $this->connection()->prepare($sql);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToQuizOverview($row);
    }

    private function filterClause(
        ?string $search,
        ?int $topicId,
        QuizStatusFilter $status,
    ): string {
        $clause = '';

        if ($search !== null) {
            $clause .= "\nAND (\n"
                . "    q.title LIKE :search_title\n"
                . "    OR q.description LIKE :search_description\n"
                . ')';
        }

        if ($topicId !== null) {
            $clause .= "\nAND q.topic_id = :topic_id";
        }

        if ($status === QuizStatusFilter::ACTIVE) {
            $clause .= "\nAND q.is_active = TRUE";
        }

        if ($status === QuizStatusFilter::INACTIVE) {
            $clause .= "\nAND q.is_active = FALSE";
        }

        return $clause;
    }

    private function orderByClause(QuizSort $sort): string
    {
        return match ($sort) {
            QuizSort::RECENT => 'ORDER BY q.updated_at DESC, q.id DESC',
            QuizSort::TITLE_ASC => 'ORDER BY q.title ASC, q.version ASC, q.id ASC',
            QuizSort::TITLE_DESC => 'ORDER BY q.title DESC, q.version DESC, q.id DESC',
        };
    }

    private function bindFilters(
        PDOStatement $statement,
        ?string $search,
        ?int $topicId,
    ): void {
        if ($search !== null) {
            $likeSearch = '%' . $search . '%';
            $statement->bindValue(':search_title', $likeSearch);
            $statement->bindValue(':search_description', $likeSearch);
        }

        if ($topicId !== null) {
            $statement->bindValue(':topic_id', $topicId, PDO::PARAM_INT);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToQuizOverview(array $row): QuizOverview
    {
        return new QuizOverview(
            id: (int) $row['id'],
            title: (string) $row['title'],
            version: (int) $row['version'],
            description: $row['description'] === null
                ? null
                : (string) $row['description'],
            isActive: (bool) (int) $row['is_active'],
            questionCount: (int) $row['question_count'],
            topicId: $row['topic_id'] === null
                ? null
                : (int) $row['topic_id'],
            topicName: $row['topic_name'] === null
                ? null
                : (string) $row['topic_name'],
            createdById: (int) $row['created_by'],
            createdByName: (string) $row['created_by_name'],
            updatedById: (int) $row['updated_by'],
            updatedByName: (string) $row['updated_by_name'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    private function connection(): PDO
    {
        return $this->database->getConnection();
    }
}
