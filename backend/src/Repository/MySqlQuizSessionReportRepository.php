<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\ParticipantType;
use CodeLandQuiz\Model\QuestionType;
use CodeLandQuiz\Model\SessionQuestionOptionOverview;
use CodeLandQuiz\Model\SessionQuestionOverview;
use CodeLandQuiz\Model\SessionReportAnswerOverview;
use CodeLandQuiz\Model\SessionReportParticipantOverview;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;

final readonly class MySqlQuizSessionReportRepository implements QuizSessionReportRepository
{
    private const FIND_QUESTIONS_SQL = <<<SQL
SELECT
    sq.id AS question_id,
    sq.session_id,
    sq.source_question_id,
    sq.question_text,
    sq.question_type,
    sq.image_path,
    sq.time_limit_seconds,
    sq.max_points,
    sq.question_order,
    sqo.id AS option_id,
    sqo.source_option_id,
    sqo.option_text,
    sqo.is_correct,
    sqo.option_order
FROM session_questions sq
LEFT JOIN session_question_options sqo
    ON sqo.session_question_id = sq.id
WHERE sq.session_id = :session_id
ORDER BY
    sq.question_order ASC,
    sqo.option_order ASC,
    sqo.id ASC
SQL;

    private const FIND_PARTICIPANTS_SQL = <<<SQL
SELECT
    sp.id AS participant_id,
    sp.participant_type,
    sp.student_id,
    student.first_name AS student_first_name,
    student.last_name AS student_last_name,
    student.username AS student_username,
    sp.nickname,
    sp.avatar_key,
    sp.total_score,
    sp.is_removed,
    sp.removed_at,
    sp.joined_at
FROM session_participants sp
LEFT JOIN students student
    ON student.id = sp.student_id
WHERE sp.session_id = :session_id
ORDER BY
    sp.joined_at ASC,
    sp.id ASC
SQL;

    private const FIND_ANSWERS_SQL = <<<SQL
SELECT
    pa.session_participant_id AS participant_id,
    pa.session_question_id,
    pa.selected_option_ids,
    pa.is_correct,
    pa.response_time_ms,
    pa.points_awarded,
    pa.answered_at
FROM participant_answers pa
INNER JOIN session_questions sq
    ON sq.id = pa.session_question_id
WHERE sq.session_id = :session_id
ORDER BY
    sq.question_order ASC,
    pa.session_participant_id ASC
SQL;

    public function __construct(
        private Database $database,
    ) {
    }

    public function findQuestions(int $sessionId): array
    {
        $statement = $this->connection()->prepare(self::FIND_QUESTIONS_SQL);
        $statement->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $statement->execute();

        return $this->mapQuestionRows($statement->fetchAll());
    }

    public function findParticipants(int $sessionId): array
    {
        $statement = $this->connection()->prepare(self::FIND_PARTICIPANTS_SQL);
        $statement->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $statement->execute();

        $participants = [];

        while (($row = $statement->fetch()) !== false) {
            $participantType = ParticipantType::from(
                (string) $row['participant_type'],
            );
            $isRegistered = $participantType === ParticipantType::REGISTERED;

            $participants[] = new SessionReportParticipantOverview(
                participantId: (int) $row['participant_id'],
                participantType: $participantType,
                studentId: $isRegistered
                    ? $this->nullableInt($row['student_id'])
                    : null,
                studentFirstName: $isRegistered
                    ? $this->nullableString($row['student_first_name'])
                    : null,
                studentLastName: $isRegistered
                    ? $this->nullableString($row['student_last_name'])
                    : null,
                studentUsername: $isRegistered
                    ? $this->nullableString($row['student_username'])
                    : null,
                nickname: (string) $row['nickname'],
                avatarKey: (string) $row['avatar_key'],
                totalScore: (int) $row['total_score'],
                isRemoved: (bool) (int) $row['is_removed'],
                removedAt: $this->nullableDateTime($row['removed_at']),
                joinedAt: new DateTimeImmutable((string) $row['joined_at']),
            );
        }

        return $participants;
    }

    public function findAnswers(int $sessionId): array
    {
        $statement = $this->connection()->prepare(self::FIND_ANSWERS_SQL);
        $statement->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $statement->execute();

        $answers = [];

        while (($row = $statement->fetch()) !== false) {
            $answers[] = new SessionReportAnswerOverview(
                participantId: (int) $row['participant_id'],
                sessionQuestionId: (int) $row['session_question_id'],
                selectedOptionIds: $this->decodeSelectedOptionIds(
                    (string) $row['selected_option_ids'],
                ),
                isCorrect: (bool) (int) $row['is_correct'],
                responseTimeMs: (int) $row['response_time_ms'],
                pointsAwarded: (int) $row['points_awarded'],
                answeredAt: new DateTimeImmutable(
                    (string) $row['answered_at'],
                ),
            );
        }

        return $answers;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return SessionQuestionOverview[]
     */
    private function mapQuestionRows(array $rows): array
    {
        $questionRows = [];
        $optionsByQuestionId = [];

        foreach ($rows as $row) {
            $questionId = (int) $row['question_id'];

            if (!isset($questionRows[$questionId])) {
                $questionRows[$questionId] = $row;
                $optionsByQuestionId[$questionId] = [];
            }

            if ($row['option_id'] === null) {
                continue;
            }

            $optionsByQuestionId[$questionId][] = new SessionQuestionOptionOverview(
                id: (int) $row['option_id'],
                sessionQuestionId: $questionId,
                sourceOptionId: $this->nullableInt($row['source_option_id']),
                optionText: (string) $row['option_text'],
                isCorrect: (bool) (int) $row['is_correct'],
                optionOrder: (int) $row['option_order'],
            );
        }

        $questions = [];

        foreach ($questionRows as $questionId => $row) {
            $questions[] = new SessionQuestionOverview(
                id: $questionId,
                sessionId: (int) $row['session_id'],
                sourceQuestionId: $this->nullableInt(
                    $row['source_question_id'],
                ),
                questionText: (string) $row['question_text'],
                questionType: QuestionType::from(
                    (string) $row['question_type'],
                ),
                imagePath: $this->nullableString($row['image_path']),
                timeLimitSeconds: (int) $row['time_limit_seconds'],
                maxPoints: (int) $row['max_points'],
                questionOrder: (int) $row['question_order'],
                options: $optionsByQuestionId[$questionId],
            );
        }

        return $questions;
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

        $seenOptionIds = [];

        foreach ($decoded as $optionId) {
            if (
                !is_int($optionId)
                || $optionId < 1
                || isset($seenOptionIds[$optionId])
            ) {
                throw new RuntimeException(
                    'Stored participant answer option IDs are invalid.',
                );
            }

            $seenOptionIds[$optionId] = true;
        }

        return $decoded;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
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
