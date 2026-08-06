<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\ParticipantType;
use CodeLandQuiz\Model\SessionQuestionParticipantResultOverview;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;

final readonly class MySqlQuizSessionResultRepository implements QuizSessionResultRepository
{
    private const RECALCULATE_PARTICIPANT_TOTAL_SCORES_SQL = <<<SQL
UPDATE session_participants sp
SET total_score = COALESCE(
    (
        SELECT SUM(pa.points_awarded)
        FROM participant_answers pa
        INNER JOIN session_questions sq
            ON sq.id = pa.session_question_id
        WHERE pa.session_participant_id = sp.id
          AND sq.session_id = :answer_session_id
    ),
    0
)
WHERE sp.session_id = :participant_session_id
SQL;

    private const FIND_QUESTION_PARTICIPANT_RESULTS_SQL = <<<SQL
SELECT
    sp.id AS participant_id,
    sp.participant_type,
    sp.nickname,
    sp.avatar_key,
    sp.total_score,
    sp.joined_at,
    pa.selected_option_ids,
    pa.is_correct,
    pa.response_time_ms,
    pa.points_awarded,
    pa.answered_at
FROM session_participants sp
LEFT JOIN participant_answers pa
    ON pa.session_participant_id = sp.id
   AND pa.session_question_id = :session_question_id
WHERE sp.session_id = :session_id
  AND sp.is_removed = FALSE
ORDER BY
    sp.joined_at ASC,
    sp.id ASC
SQL;

    public function __construct(
        private Database $database,
    ) {
    }

    public function recalculateParticipantTotalScores(
        int $sessionId,
    ): void {
        $statement = $this->connection()->prepare(
            self::RECALCULATE_PARTICIPANT_TOTAL_SCORES_SQL,
        );
        $statement->bindValue(
            ':answer_session_id',
            $sessionId,
            PDO::PARAM_INT,
        );
        $statement->bindValue(
            ':participant_session_id',
            $sessionId,
            PDO::PARAM_INT,
        );
        $statement->execute();
    }

    public function findQuestionParticipantResults(
        int $sessionId,
        int $sessionQuestionId,
    ): array {
        $statement = $this->connection()->prepare(
            self::FIND_QUESTION_PARTICIPANT_RESULTS_SQL,
        );
        $statement->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $statement->bindValue(
            ':session_question_id',
            $sessionQuestionId,
            PDO::PARAM_INT,
        );
        $statement->execute();

        $results = [];

        while (($row = $statement->fetch()) !== false) {
            $results[] = $this->mapRowToOverview($row);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToOverview(
        array $row,
    ): SessionQuestionParticipantResultOverview {
        $hasAnswer = $row['selected_option_ids'] !== null;

        return new SessionQuestionParticipantResultOverview(
            participantId: (int) $row['participant_id'],
            participantType: ParticipantType::from(
                (string) $row['participant_type'],
            ),
            nickname: (string) $row['nickname'],
            avatarKey: (string) $row['avatar_key'],
            totalScore: (int) $row['total_score'],
            joinedAt: new DateTimeImmutable((string) $row['joined_at']),
            selectedOptionIds: $hasAnswer
                ? $this->decodeSelectedOptionIds(
                    (string) $row['selected_option_ids'],
                )
                : null,
            isCorrect: $hasAnswer
                ? (bool) (int) $row['is_correct']
                : null,
            responseTimeMs: $hasAnswer
                ? (int) $row['response_time_ms']
                : null,
            pointsAwarded: $hasAnswer
                ? (int) $row['points_awarded']
                : 0,
            answeredAt: $hasAnswer
                ? new DateTimeImmutable((string) $row['answered_at'])
                : null,
        );
    }

    /**
     * @return int[]
     */
    private function decodeSelectedOptionIds(string $value): array
    {
        try {
            $decoded = json_decode(
                $value,
                false,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Stored participant answer option IDs are invalid.',
                0,
                $exception,
            );
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException(
                'Stored participant answer option IDs are invalid.',
            );
        }

        $uniqueOptionIds = [];

        foreach ($decoded as $optionId) {
            if (
                !is_int($optionId)
                || $optionId < 1
                || isset($uniqueOptionIds[$optionId])
            ) {
                throw new RuntimeException(
                    'Stored participant answer option IDs are invalid.',
                );
            }

            $uniqueOptionIds[$optionId] = true;
        }

        return $decoded;
    }

    private function connection(): PDO
    {
        return $this->database->getConnection();
    }
}
