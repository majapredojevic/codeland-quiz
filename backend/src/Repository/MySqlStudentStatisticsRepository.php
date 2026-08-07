<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\DTO\StudentStatisticsSessionQueryDTO;
use CodeLandQuiz\Model\StudentSessionPerformanceOverview;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use PDO;
use PDOStatement;
use RuntimeException;

final readonly class MySqlStudentStatisticsRepository implements StudentStatisticsRepository
{
    private const PERFORMANCE_SQL = <<<'SQL'
WITH qualifying_sessions AS (
    SELECT
        qs.id AS session_id,
        qs.quiz_id,
        qs.quiz_title,
        qs.quiz_version,
        qs.started_at,
        qs.ended_at,
        target_participant.id AS target_participant_id
    FROM quiz_sessions qs
    INNER JOIN session_participants target_participant
        ON target_participant.session_id = qs.id
    WHERE qs.status = 'FINISHED'
      AND target_participant.participant_type = 'REGISTERED'
      AND target_participant.student_id = :student_id
      AND target_participant.is_removed = FALSE%s
),
participant_answer_stats AS (
    SELECT
        sp.id AS participant_id,
        sp.session_id,
        sp.joined_at,
        sp.total_score,
        COUNT(answered_question.id) AS answer_count,
        COALESCE(
            SUM(
                CASE
                    WHEN answered_question.id IS NOT NULL
                         AND pa.is_correct = TRUE THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS correct_answer_count,
        COALESCE(
            SUM(
                CASE
                    WHEN answered_question.id IS NOT NULL
                        THEN pa.response_time_ms
                    ELSE 0
                END
            ),
            0
        ) AS total_response_time_ms
    FROM qualifying_sessions qs
    INNER JOIN session_participants sp
        ON sp.session_id = qs.session_id
    LEFT JOIN participant_answers pa
        ON pa.session_participant_id = sp.id
    LEFT JOIN session_questions answered_question
        ON answered_question.id = pa.session_question_id
       AND answered_question.session_id = sp.session_id
    WHERE sp.is_removed = FALSE
    GROUP BY
        sp.id,
        sp.session_id,
        sp.joined_at,
        sp.total_score
),
session_question_stats AS (
    SELECT
        qs.session_id,
        COUNT(sq.id) AS question_count,
        COALESCE(SUM(sq.max_points), 0) AS max_possible_score
    FROM qualifying_sessions qs
    LEFT JOIN session_questions sq
        ON sq.session_id = qs.session_id
    GROUP BY qs.session_id
),
session_participant_counts AS (
    SELECT
        pas.session_id,
        COUNT(*) AS participant_count
    FROM participant_answer_stats pas
    GROUP BY pas.session_id
),
ranked_participants AS (
    SELECT
        pas.*,
        ROW_NUMBER() OVER (
            PARTITION BY pas.session_id
            ORDER BY
                pas.total_score DESC,
                pas.correct_answer_count DESC,
                pas.answer_count DESC,
                pas.total_response_time_ms ASC,
                pas.joined_at ASC,
                pas.participant_id ASC
        ) AS final_rank
    FROM participant_answer_stats pas
)
SELECT
    qs.session_id,
    qs.quiz_id,
    qs.quiz_title,
    qs.quiz_version,
    rp.participant_id,
    qs.started_at AS session_started_at,
    qs.ended_at AS session_ended_at,
    sqs.question_count,
    sqs.max_possible_score,
    rp.total_score,
    rp.answer_count,
    rp.correct_answer_count,
    rp.total_response_time_ms,
    spc.participant_count,
    rp.final_rank
FROM qualifying_sessions qs
INNER JOIN ranked_participants rp
    ON rp.participant_id = qs.target_participant_id
INNER JOIN session_question_stats sqs
    ON sqs.session_id = qs.session_id
INNER JOIN session_participant_counts spc
    ON spc.session_id = qs.session_id
ORDER BY
    qs.ended_at %s,
    qs.session_id %s%s
SQL;

    private const COUNT_SQL = <<<'SQL'
SELECT COUNT(*)
FROM quiz_sessions qs
INNER JOIN session_participants sp
    ON sp.session_id = qs.id
WHERE qs.status = 'FINISHED'
  AND sp.participant_type = 'REGISTERED'
  AND sp.student_id = :student_id
  AND sp.is_removed = FALSE%s
SQL;

    public function __construct(
        private Database $database,
    ) {
    }

    public function findAllPerformances(
        int $studentId,
    ): array {
        return $this->findPerformances(
            studentId: $studentId,
            quizId: null,
            newestFirst: false,
            pageSize: null,
            offset: null,
        );
    }

    public function findPerformancePage(
        int $studentId,
        StudentStatisticsSessionQueryDTO $query,
    ): array {
        return $this->findPerformances(
            studentId: $studentId,
            quizId: $query->quizId,
            newestFirst: true,
            pageSize: $query->pageSize,
            offset: $query->getOffset(),
        );
    }

    public function countPerformances(
        int $studentId,
        StudentStatisticsSessionQueryDTO $query,
    ): int {
        $sql = sprintf(
            self::COUNT_SQL,
            $this->quizFilterClause($query->quizId),
        );
        $statement = $this->connection()->prepare($sql);

        $this->bindFilters($statement, $studentId, $query->quizId);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * @return StudentSessionPerformanceOverview[]
     */
    private function findPerformances(
        int $studentId,
        ?int $quizId,
        bool $newestFirst,
        ?int $pageSize,
        ?int $offset,
    ): array {
        $direction = $newestFirst ? 'DESC' : 'ASC';
        $paginationClause = $pageSize === null
            ? ''
            : "\nLIMIT :limit\nOFFSET :offset";
        $sql = sprintf(
            self::PERFORMANCE_SQL,
            $this->quizFilterClause($quizId),
            $direction,
            $direction,
            $paginationClause,
        );
        $statement = $this->connection()->prepare($sql);

        $this->bindFilters($statement, $studentId, $quizId);

        if ($pageSize !== null && $offset !== null) {
            $statement->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        }

        $statement->execute();
        $performances = [];

        while (($row = $statement->fetch()) !== false) {
            $performances[] = $this->mapRowToOverview($row);
        }

        return $performances;
    }

    private function quizFilterClause(?int $quizId): string
    {
        if ($quizId === null) {
            return '';
        }

        return "\n      AND qs.quiz_id = :quiz_id";
    }

    private function bindFilters(
        PDOStatement $statement,
        int $studentId,
        ?int $quizId,
    ): void {
        $statement->bindValue(':student_id', $studentId, PDO::PARAM_INT);

        if ($quizId !== null) {
            $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToOverview(
        array $row,
    ): StudentSessionPerformanceOverview {
        $startedAt = $row['session_started_at'];
        $endedAt = $row['session_ended_at'];

        if ($startedAt === null || $endedAt === null) {
            throw new RuntimeException(
                'Finished quiz session timestamps are missing.',
            );
        }

        return new StudentSessionPerformanceOverview(
            sessionId: (int) $row['session_id'],
            quizId: (int) $row['quiz_id'],
            quizTitle: (string) $row['quiz_title'],
            quizVersion: (int) $row['quiz_version'],
            participantId: (int) $row['participant_id'],
            sessionStartedAt: new DateTimeImmutable((string) $startedAt),
            sessionEndedAt: new DateTimeImmutable((string) $endedAt),
            questionCount: (int) $row['question_count'],
            maxPossibleScore: (int) $row['max_possible_score'],
            totalScore: (int) $row['total_score'],
            answerCount: (int) $row['answer_count'],
            correctAnswerCount: (int) $row['correct_answer_count'],
            totalResponseTimeMs: (int) $row['total_response_time_ms'],
            participantCount: (int) $row['participant_count'],
            finalRank: (int) $row['final_rank'],
        );
    }

    private function connection(): PDO
    {
        return $this->database->getConnection();
    }
}
