<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\QuestionType;
use CodeLandQuiz\Model\QuizQuestionSessionStatisticsOverview;
use CodeLandQuiz\Model\QuizStatisticsSummaryOverview;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final readonly class MySqlQuizStatisticsRepository implements QuizStatisticsRepository
{
    private const FIND_SUMMARY_SQL = <<<SQL
WITH finished_sessions AS (
    SELECT qs.id
    FROM quiz_sessions qs
    WHERE qs.quiz_id = :quiz_id
      AND qs.status = 'FINISHED'
),
participants AS (
    SELECT
        sp.id,
        sp.session_id,
        sp.participant_type,
        sp.student_id,
        sp.total_score
    FROM session_participants sp
    INNER JOIN finished_sessions fs
        ON fs.id = sp.session_id
    WHERE sp.is_removed = FALSE
),
participant_counts AS (
    SELECT
        fs.id AS session_id,
        COUNT(p.id) AS participant_count
    FROM finished_sessions fs
    LEFT JOIN participants p
        ON p.session_id = fs.id
    GROUP BY fs.id
),
question_counts AS (
    SELECT
        fs.id AS session_id,
        COUNT(sq.id) AS question_count
    FROM finished_sessions fs
    LEFT JOIN session_questions sq
        ON sq.session_id = fs.id
    GROUP BY fs.id
),
session_counts AS (
    SELECT
        fs.id AS session_id,
        pc.participant_count,
        qc.question_count
    FROM finished_sessions fs
    INNER JOIN participant_counts pc
        ON pc.session_id = fs.id
    INNER JOIN question_counts qc
        ON qc.session_id = fs.id
),
valid_answers AS (
    SELECT
        pa.id,
        pa.is_correct
    FROM participant_answers pa
    INNER JOIN participants p
        ON p.id = pa.session_participant_id
    INNER JOIN session_questions sq
        ON sq.id = pa.session_question_id
       AND sq.session_id = p.session_id
)
SELECT
    (SELECT COUNT(*) FROM finished_sessions) AS finished_session_count,
    (SELECT COUNT(*) FROM participants) AS participant_entry_count,
    (
        SELECT COUNT(*)
        FROM participants p
        WHERE p.participant_type = 'REGISTERED'
    ) AS registered_participation_count,
    (
        SELECT COUNT(*)
        FROM participants p
        WHERE p.participant_type = 'GUEST'
    ) AS guest_participation_count,
    (
        SELECT COUNT(DISTINCT p.student_id)
        FROM participants p
        WHERE p.participant_type = 'REGISTERED'
    ) AS unique_registered_student_count,
    COALESCE(
        (
            SELECT SUM(sc.participant_count * sc.question_count)
            FROM session_counts sc
        ),
        0
    ) AS total_possible_answer_count,
    (SELECT COUNT(*) FROM valid_answers) AS answer_count,
    COALESCE(
        (
            SELECT SUM(
                CASE
                    WHEN va.is_correct = TRUE THEN 1
                    ELSE 0
                END
            )
            FROM valid_answers va
        ),
        0
    ) AS correct_answer_count,
    COALESCE(
        (SELECT MAX(p.total_score) FROM participants p),
        0
    ) AS highest_score,
    COALESCE(
        (SELECT SUM(p.total_score) FROM participants p),
        0
    ) AS total_score_sum
SQL;

    private const FIND_QUESTION_SESSION_STATISTICS_SQL = <<<SQL
WITH finished_sessions AS (
    SELECT
        qs.id,
        qs.ended_at
    FROM quiz_sessions qs
    WHERE qs.quiz_id = :quiz_id
      AND qs.status = 'FINISHED'
),
participant_counts AS (
    SELECT
        fs.id AS session_id,
        COUNT(sp.id) AS participant_count
    FROM finished_sessions fs
    LEFT JOIN session_participants sp
        ON sp.session_id = fs.id
       AND sp.is_removed = FALSE
    GROUP BY fs.id
),
answer_stats AS (
    SELECT
        pa.session_question_id,
        COUNT(*) AS answer_count,
        SUM(
            CASE
                WHEN pa.is_correct = TRUE THEN 1
                ELSE 0
            END
        ) AS correct_answer_count,
        SUM(pa.response_time_ms) AS total_response_time_ms,
        SUM(pa.points_awarded) AS total_points_awarded
    FROM participant_answers pa
    INNER JOIN session_participants sp
        ON sp.id = pa.session_participant_id
       AND sp.is_removed = FALSE
    INNER JOIN session_questions answered_question
        ON answered_question.id = pa.session_question_id
       AND answered_question.session_id = sp.session_id
    INNER JOIN finished_sessions fs
        ON fs.id = answered_question.session_id
    GROUP BY pa.session_question_id
)
SELECT
    sq.id AS session_question_id,
    sq.session_id,
    sq.source_question_id,
    sq.question_text,
    sq.question_type,
    sq.question_order,
    fs.ended_at AS session_ended_at,
    source_question.id AS current_source_question_id,
    source_question.is_deleted AS source_question_is_deleted,
    COALESCE(pc.participant_count, 0) AS participant_opportunity_count,
    COALESCE(answer_stats.answer_count, 0) AS answer_count,
    COALESCE(answer_stats.correct_answer_count, 0) AS correct_answer_count,
    COALESCE(answer_stats.total_response_time_ms, 0) AS total_response_time_ms,
    COALESCE(answer_stats.total_points_awarded, 0) AS total_points_awarded
FROM finished_sessions fs
INNER JOIN session_questions sq
    ON sq.session_id = fs.id
INNER JOIN participant_counts pc
    ON pc.session_id = fs.id
LEFT JOIN answer_stats
    ON answer_stats.session_question_id = sq.id
LEFT JOIN questions source_question
    ON source_question.id = sq.source_question_id
ORDER BY
    fs.ended_at ASC,
    fs.id ASC,
    sq.question_order ASC,
    sq.id ASC
SQL;

    public function __construct(
        private Database $database,
    ) {
    }

    public function findSummary(
        int $quizId,
    ): QuizStatisticsSummaryOverview {
        $statement = $this->connection()->prepare(self::FIND_SUMMARY_SQL);
        $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        if ($row === false) {
            throw new RuntimeException('Quiz statistics summary was not returned.');
        }

        return $this->mapSummary($row);
    }

    public function findQuestionSessionStatistics(
        int $quizId,
    ): array {
        $statement = $this->connection()->prepare(
            self::FIND_QUESTION_SESSION_STATISTICS_SQL,
        );
        $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        $statement->execute();

        $statistics = [];

        while (($row = $statement->fetch()) !== false) {
            $statistics[] = $this->mapQuestionSessionStatistics($row);
        }

        return $statistics;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapSummary(
        array $row,
    ): QuizStatisticsSummaryOverview {
        $finishedSessionCount = (int) $row['finished_session_count'];
        $participantEntryCount = (int) $row['participant_entry_count'];
        $totalPossibleAnswerCount = (int) $row['total_possible_answer_count'];
        $answerCount = (int) $row['answer_count'];
        $correctAnswerCount = (int) $row['correct_answer_count'];
        $totalScoreSum = (int) $row['total_score_sum'];

        return new QuizStatisticsSummaryOverview(
            finishedSessionCount: $finishedSessionCount,
            participantEntryCount: $participantEntryCount,
            registeredParticipationCount: (int) $row['registered_participation_count'],
            guestParticipationCount: (int) $row['guest_participation_count'],
            uniqueRegisteredStudentCount: (int) $row['unique_registered_student_count'],
            totalPossibleAnswerCount: $totalPossibleAnswerCount,
            answerCount: $answerCount,
            correctAnswerCount: $correctAnswerCount,
            incorrectAnswerCount: max(0, $answerCount - $correctAnswerCount),
            unansweredCount: max(0, $totalPossibleAnswerCount - $answerCount),
            accuracyPercentage: $answerCount === 0
                ? null
                : round(
                    $correctAnswerCount / $answerCount * 100,
                    2,
                    PHP_ROUND_HALF_UP,
                ),
            answerRatePercentage: $totalPossibleAnswerCount === 0
                ? null
                : round(
                    $answerCount / $totalPossibleAnswerCount * 100,
                    2,
                    PHP_ROUND_HALF_UP,
                ),
            highestScore: (int) $row['highest_score'],
            averageScore: $participantEntryCount === 0
                ? null
                : (int) round(
                    $totalScoreSum / $participantEntryCount,
                    0,
                    PHP_ROUND_HALF_UP,
                ),
            averageParticipantsPerSession: $finishedSessionCount === 0
                ? null
                : round(
                    $participantEntryCount / $finishedSessionCount,
                    2,
                    PHP_ROUND_HALF_UP,
                ),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapQuestionSessionStatistics(
        array $row,
    ): QuizQuestionSessionStatisticsOverview {
        $sessionEndedAt = $row['session_ended_at'];

        if ($sessionEndedAt === null) {
            throw new RuntimeException(
                'Finished quiz session end timestamp is missing.',
            );
        }

        $sourceQuestionId = $row['source_question_id'] === null
            ? null
            : (int) $row['source_question_id'];
        $sourceQuestionCurrentlyDeleted = $sourceQuestionId === null
            || $row['current_source_question_id'] === null
            || (bool) (int) $row['source_question_is_deleted'];

        return new QuizQuestionSessionStatisticsOverview(
            sessionQuestionId: (int) $row['session_question_id'],
            sessionId: (int) $row['session_id'],
            sourceQuestionId: $sourceQuestionId,
            questionText: (string) $row['question_text'],
            questionType: QuestionType::from((string) $row['question_type']),
            questionOrder: (int) $row['question_order'],
            sessionEndedAt: new DateTimeImmutable((string) $sessionEndedAt),
            sourceQuestionCurrentlyDeleted: $sourceQuestionCurrentlyDeleted,
            participantOpportunityCount: (int) $row['participant_opportunity_count'],
            answerCount: (int) $row['answer_count'],
            correctAnswerCount: (int) $row['correct_answer_count'],
            totalResponseTimeMs: (int) $row['total_response_time_ms'],
            totalPointsAwarded: (int) $row['total_points_awarded'],
        );
    }

    private function connection(): PDO
    {
        return $this->database->getConnection();
    }
}
