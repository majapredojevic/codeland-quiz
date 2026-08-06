<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Game\Exception\AnswerAlreadySubmittedException;
use CodeLandQuiz\Model\ParticipantAnswerOverview;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;

final readonly class MySqlParticipantAnswerRepository implements ParticipantAnswerRepository
{
    private const DUPLICATE_ANSWER_INDEX = 'uq_participant_answers_question';

    private const FIND_BY_PARTICIPANT_AND_QUESTION_SQL = <<<SQL
SELECT
    id,
    session_participant_id,
    session_question_id,
    selected_option_ids,
    is_correct,
    response_time_ms,
    points_awarded,
    answered_at
FROM participant_answers
WHERE session_participant_id = :participant_id
  AND session_question_id = :session_question_id
LIMIT 1
SQL;

    private const INSERT_SQL = <<<SQL
INSERT INTO participant_answers (
    session_participant_id,
    session_question_id,
    selected_option_ids,
    is_correct,
    response_time_ms,
    points_awarded,
    answered_at
) VALUES (
    :participant_id,
    :session_question_id,
    :selected_option_ids,
    :is_correct,
    :response_time_ms,
    :points_awarded,
    :answered_at
)
SQL;

    public function __construct(
        private Database $database,
    ) {
    }

    public function findByParticipantAndQuestion(
        int $participantId,
        int $sessionQuestionId,
    ): ?ParticipantAnswerOverview {
        $statement = $this->connection()->prepare(
            self::FIND_BY_PARTICIPANT_AND_QUESTION_SQL,
        );
        $statement->bindValue(':participant_id', $participantId, PDO::PARAM_INT);
        $statement->bindValue(
            ':session_question_id',
            $sessionQuestionId,
            PDO::PARAM_INT,
        );
        $statement->execute();

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToOverview($row);
    }

    /**
     * @param int[] $selectedOptionIds
     */
    public function create(
        int $participantId,
        int $sessionQuestionId,
        array $selectedOptionIds,
        bool $isCorrect,
        int $responseTimeMs,
        int $pointsAwarded,
        DateTimeImmutable $answeredAt,
    ): int {
        $sortedOptionIds = $selectedOptionIds;
        sort($sortedOptionIds, SORT_NUMERIC);

        $statement = $this->connection()->prepare(self::INSERT_SQL);
        $statement->bindValue(':participant_id', $participantId, PDO::PARAM_INT);
        $statement->bindValue(
            ':session_question_id',
            $sessionQuestionId,
            PDO::PARAM_INT,
        );
        $statement->bindValue(
            ':selected_option_ids',
            $this->encodeSelectedOptionIds($sortedOptionIds),
        );
        $statement->bindValue(
            ':is_correct',
            $isCorrect ? 1 : 0,
            PDO::PARAM_INT,
        );
        $statement->bindValue(
            ':response_time_ms',
            $responseTimeMs,
            PDO::PARAM_INT,
        );
        $statement->bindValue(
            ':points_awarded',
            $pointsAwarded,
            PDO::PARAM_INT,
        );
        $statement->bindValue(':answered_at', $this->formatDateTime($answeredAt));

        try {
            $statement->execute();
        } catch (PDOException $exception) {
            $this->throwDuplicateAnswerIfNeeded($exception);

            throw $exception;
        }

        $id = (int) $this->connection()->lastInsertId();

        if ($id < 1) {
            throw new RuntimeException('Participant answer ID was not returned.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToOverview(array $row): ParticipantAnswerOverview
    {
        return new ParticipantAnswerOverview(
            id: (int) $row['id'],
            participantId: (int) $row['session_participant_id'],
            sessionQuestionId: (int) $row['session_question_id'],
            selectedOptionIds: $this->decodeSelectedOptionIds(
                (string) $row['selected_option_ids'],
            ),
            isCorrect: (bool) (int) $row['is_correct'],
            responseTimeMs: (int) $row['response_time_ms'],
            pointsAwarded: (int) $row['points_awarded'],
            answeredAt: new DateTimeImmutable((string) $row['answered_at']),
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
                true,
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

        foreach ($decoded as $optionId) {
            if (!is_int($optionId) || $optionId < 1) {
                throw new RuntimeException(
                    'Stored participant answer option IDs are invalid.',
                );
            }
        }

        return $decoded;
    }

    /**
     * @param int[] $selectedOptionIds
     */
    private function encodeSelectedOptionIds(array $selectedOptionIds): string
    {
        return json_encode($selectedOptionIds, JSON_THROW_ON_ERROR);
    }

    private function throwDuplicateAnswerIfNeeded(
        PDOException $exception,
    ): void {
        $errorInfo = $exception->errorInfo;
        $sqlState = (string) ($errorInfo[0] ?? '');
        $driverCode = (int) ($errorInfo[1] ?? 0);
        $message = (string) ($errorInfo[2] ?? $exception->getMessage());

        if (
            $sqlState === '23000'
            && $driverCode === 1062
            && str_contains($message, self::DUPLICATE_ANSWER_INDEX)
        ) {
            throw new AnswerAlreadySubmittedException(
                'An answer has already been submitted for this question.',
                0,
                $exception,
            );
        }
    }

    private function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }

    private function connection(): PDO
    {
        return $this->database->getConnection();
    }
}
