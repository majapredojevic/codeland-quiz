<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\QuestionOptionOverview;
use CodeLandQuiz\Model\QuestionOverview;
use CodeLandQuiz\Model\QuestionType;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use PDO;
use PDOStatement;
use RuntimeException;

final readonly class MySqlQuestionRepository implements QuestionRepository
{
    private const SELECT_WITH_OPTIONS_SQL = <<<SQL
SELECT
    q.id AS question_id,
    q.quiz_id,
    q.question_text,
    q.question_type,
    q.image_path,
    q.time_limit_seconds,
    q.max_points,
    q.question_order,
    q.created_at,
    q.updated_at,
    qo.id AS option_id,
    qo.option_text,
    qo.is_correct,
    qo.option_order
FROM questions q
LEFT JOIN question_options qo
    ON qo.question_id = q.id
SQL;

    private const FIND_ALL_BY_QUIZ_ID_SQL = self::SELECT_WITH_OPTIONS_SQL . <<<SQL

WHERE q.quiz_id = :quiz_id
  AND q.is_deleted = FALSE
ORDER BY
    q.question_order ASC,
    qo.option_order ASC,
    qo.id ASC
SQL;

    private const FIND_OVERVIEW_BY_QUIZ_AND_ID_SQL = self::SELECT_WITH_OPTIONS_SQL . <<<SQL

WHERE q.quiz_id = :quiz_id
  AND q.id = :question_id
  AND q.is_deleted = FALSE
ORDER BY
    qo.option_order ASC,
    qo.id ASC
SQL;

    private const LOCK_BY_QUIZ_AND_ID_SQL = <<<SQL
SELECT id
FROM questions
WHERE quiz_id = :quiz_id
  AND id = :question_id
  AND is_deleted = FALSE
FOR UPDATE
SQL;

    private const GET_NEXT_ACTIVE_ORDER_SQL = <<<SQL
SELECT COALESCE(MAX(question_order), 0) + 1
FROM questions
WHERE quiz_id = :quiz_id
  AND is_deleted = FALSE
SQL;

    private const INSERT_QUESTION_SQL = <<<SQL
INSERT INTO questions (
    quiz_id,
    question_text,
    question_type,
    image_path,
    time_limit_seconds,
    max_points,
    question_order,
    is_deleted
) VALUES (
    :quiz_id,
    :question_text,
    :question_type,
    :image_path,
    :time_limit_seconds,
    :max_points,
    :question_order,
    FALSE
)
SQL;

    private const UPDATE_QUESTION_SQL = <<<SQL
UPDATE questions
SET question_text = :question_text,
    question_type = :question_type,
    image_path = :image_path,
    time_limit_seconds = :time_limit_seconds,
    max_points = :max_points
WHERE id = :question_id
  AND is_deleted = FALSE
SQL;

    private const DELETE_OPTIONS_SQL = <<<SQL
DELETE FROM question_options
WHERE question_id = :question_id
SQL;

    private const SOFT_DELETE_SQL = <<<SQL
UPDATE questions
SET is_deleted = TRUE,
    deleted_at = CURRENT_TIMESTAMP
WHERE id = :question_id
  AND is_deleted = FALSE
SQL;

    private const SHIFT_ACTIVE_ORDERS_AFTER_DELETION_SQL = <<<SQL
UPDATE questions
SET question_order = question_order - 1
WHERE quiz_id = :quiz_id
  AND is_deleted = FALSE
  AND question_order > :deleted_question_order
ORDER BY question_order ASC
SQL;

    private const FIND_ACTIVE_IDS_ORDERED_FOR_UPDATE_SQL = <<<SQL
SELECT id
FROM questions
WHERE quiz_id = :quiz_id
  AND is_deleted = FALSE
ORDER BY question_order ASC, id ASC
FOR UPDATE
SQL;

    private const MOVE_ACTIVE_ORDERS_TO_TEMPORARY_VALUES_SQL = <<<SQL
UPDATE questions
SET question_order = -question_order
WHERE quiz_id = :quiz_id
  AND is_deleted = FALSE
SQL;

    private const UPDATE_QUESTION_ORDER_SQL = <<<SQL
UPDATE questions
SET question_order = :question_order
WHERE quiz_id = :quiz_id
  AND id = :question_id
  AND is_deleted = FALSE
SQL;

    private const COUNT_ACTIVE_BY_QUIZ_ID_SQL = <<<SQL
SELECT COUNT(*)
FROM questions
WHERE quiz_id = :quiz_id
  AND is_deleted = FALSE
SQL;

    private const INSERT_OPTION_SQL = <<<SQL
INSERT INTO question_options (
    question_id,
    option_text,
    is_correct,
    option_order
) VALUES (
    :question_id,
    :option_text,
    :is_correct,
    :option_order
)
SQL;

    public function __construct(
        private Database $database,
    ) {
    }

    /**
     * @return QuestionOverview[]
     */
    public function findAllByQuizId(int $quizId): array
    {
        $statement = $this->connection()->prepare(
            self::FIND_ALL_BY_QUIZ_ID_SQL,
        );
        $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        $statement->execute();

        return $this->mapRowsToQuestions($statement->fetchAll());
    }

    public function findOverviewByQuizAndId(
        int $quizId,
        int $questionId,
    ): ?QuestionOverview {
        return $this->findOneOverviewByQuizAndId($quizId, $questionId);
    }

    public function findOverviewByQuizAndIdForUpdate(
        int $quizId,
        int $questionId,
    ): ?QuestionOverview {
        $statement = $this->connection()->prepare(
            self::LOCK_BY_QUIZ_AND_ID_SQL,
        );
        $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        $statement->bindValue(':question_id', $questionId, PDO::PARAM_INT);
        $statement->execute();

        if ($statement->fetch() === false) {
            return null;
        }

        return $this->findOneOverviewByQuizAndId($quizId, $questionId);
    }

    private function findOneOverviewByQuizAndId(
        int $quizId,
        int $questionId,
    ): ?QuestionOverview {
        $statement = $this->connection()->prepare(
            self::FIND_OVERVIEW_BY_QUIZ_AND_ID_SQL,
        );
        $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        $statement->bindValue(':question_id', $questionId, PDO::PARAM_INT);
        $statement->execute();

        $questions = $this->mapRowsToQuestions($statement->fetchAll());

        return $questions[0] ?? null;
    }

    public function getNextActiveOrder(int $quizId): int
    {
        $statement = $this->connection()->prepare(
            self::GET_NEXT_ACTIVE_ORDER_SQL,
        );
        $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function create(
        int $quizId,
        string $questionText,
        QuestionType $questionType,
        ?string $imagePath,
        int $timeLimitSeconds,
        int $maxPoints,
        int $questionOrder,
    ): int {
        $statement = $this->connection()->prepare(self::INSERT_QUESTION_SQL);
        $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        $statement->bindValue(':question_text', $questionText);
        $statement->bindValue(':question_type', $questionType->value);
        $this->bindNullableString($statement, ':image_path', $imagePath);
        $statement->bindValue(
            ':time_limit_seconds',
            $timeLimitSeconds,
            PDO::PARAM_INT,
        );
        $statement->bindValue(':max_points', $maxPoints, PDO::PARAM_INT);
        $statement->bindValue(
            ':question_order',
            $questionOrder,
            PDO::PARAM_INT,
        );
        $statement->execute();

        return $this->lastInsertId('Question ID was not returned.');
    }

    public function createOption(
        int $questionId,
        string $optionText,
        bool $isCorrect,
        int $optionOrder,
    ): int {
        $statement = $this->connection()->prepare(self::INSERT_OPTION_SQL);
        $statement->bindValue(':question_id', $questionId, PDO::PARAM_INT);
        $statement->bindValue(':option_text', $optionText);
        $statement->bindValue(
            ':is_correct',
            $isCorrect ? 1 : 0,
            PDO::PARAM_INT,
        );
        $statement->bindValue(':option_order', $optionOrder, PDO::PARAM_INT);
        $statement->execute();

        return $this->lastInsertId('Question option ID was not returned.');
    }

    public function update(
        int $questionId,
        string $questionText,
        QuestionType $questionType,
        ?string $imagePath,
        int $timeLimitSeconds,
        int $maxPoints,
    ): void {
        $statement = $this->connection()->prepare(self::UPDATE_QUESTION_SQL);
        $statement->bindValue(':question_id', $questionId, PDO::PARAM_INT);
        $statement->bindValue(':question_text', $questionText);
        $statement->bindValue(':question_type', $questionType->value);
        $this->bindNullableString($statement, ':image_path', $imagePath);
        $statement->bindValue(
            ':time_limit_seconds',
            $timeLimitSeconds,
            PDO::PARAM_INT,
        );
        $statement->bindValue(':max_points', $maxPoints, PDO::PARAM_INT);
        $statement->execute();
    }

    public function deleteOptions(int $questionId): void
    {
        $statement = $this->connection()->prepare(self::DELETE_OPTIONS_SQL);
        $statement->bindValue(':question_id', $questionId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function softDelete(int $questionId): void
    {
        $statement = $this->connection()->prepare(self::SOFT_DELETE_SQL);
        $statement->bindValue(':question_id', $questionId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function shiftActiveOrdersAfterDeletion(
        int $quizId,
        int $deletedQuestionOrder,
    ): void {
        $statement = $this->connection()->prepare(
            self::SHIFT_ACTIVE_ORDERS_AFTER_DELETION_SQL,
        );
        $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        $statement->bindValue(
            ':deleted_question_order',
            $deletedQuestionOrder,
            PDO::PARAM_INT,
        );
        $statement->execute();
    }

    /**
     * @return int[]
     */
    public function findActiveIdsOrderedForUpdate(int $quizId): array
    {
        $statement = $this->connection()->prepare(
            self::FIND_ACTIVE_IDS_ORDERED_FOR_UPDATE_SQL,
        );
        $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (mixed $questionId): int => (int) $questionId,
            $statement->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function moveActiveOrdersToTemporaryValues(int $quizId): void
    {
        $statement = $this->connection()->prepare(
            self::MOVE_ACTIVE_ORDERS_TO_TEMPORARY_VALUES_SQL,
        );
        $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function updateQuestionOrder(
        int $quizId,
        int $questionId,
        int $questionOrder,
    ): void {
        $statement = $this->connection()->prepare(
            self::UPDATE_QUESTION_ORDER_SQL,
        );
        $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        $statement->bindValue(':question_id', $questionId, PDO::PARAM_INT);
        $statement->bindValue(':question_order', $questionOrder, PDO::PARAM_INT);
        $statement->execute();
    }

    public function countActiveByQuizId(int $quizId): int
    {
        $statement = $this->connection()->prepare(
            self::COUNT_ACTIVE_BY_QUIZ_ID_SQL,
        );
        $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    private function bindNullableString(
        PDOStatement $statement,
        string $parameter,
        ?string $value,
    ): void {
        if ($value === null) {
            $statement->bindValue($parameter, null, PDO::PARAM_NULL);

            return;
        }

        $statement->bindValue($parameter, $value, PDO::PARAM_STR);
    }

    private function lastInsertId(string $message): int
    {
        $id = (int) $this->connection()->lastInsertId();

        if ($id < 1) {
            throw new RuntimeException($message);
        }

        return $id;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return QuestionOverview[]
     */
    private function mapRowsToQuestions(array $rows): array
    {
        $questions = [];

        foreach ($rows as $row) {
            $questionId = (int) $row['question_id'];

            if (!isset($questions[$questionId])) {
                $questions[$questionId] = [
                    'row' => $row,
                    'options' => [],
                ];
            }

            if ($row['option_id'] !== null) {
                $questions[$questionId]['options'][] = $this->mapRowToOption(
                    $row,
                );
            }
        }

        return array_map(
            fn (array $question): QuestionOverview => $this->mapRowToQuestion(
                $question['row'],
                $question['options'],
            ),
            array_values($questions),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param QuestionOptionOverview[] $options
     */
    private function mapRowToQuestion(
        array $row,
        array $options,
    ): QuestionOverview {
        return new QuestionOverview(
            id: (int) $row['question_id'],
            quizId: (int) $row['quiz_id'],
            questionText: (string) $row['question_text'],
            questionType: QuestionType::from((string) $row['question_type']),
            imagePath: $row['image_path'] === null
                ? null
                : (string) $row['image_path'],
            timeLimitSeconds: (int) $row['time_limit_seconds'],
            maxPoints: (int) $row['max_points'],
            questionOrder: (int) $row['question_order'],
            options: $options,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToOption(array $row): QuestionOptionOverview
    {
        return new QuestionOptionOverview(
            id: (int) $row['option_id'],
            optionText: (string) $row['option_text'],
            isCorrect: (bool) (int) $row['is_correct'],
            optionOrder: (int) $row['option_order'],
        );
    }

    private function connection(): PDO
    {
        return $this->database->getConnection();
    }
}
