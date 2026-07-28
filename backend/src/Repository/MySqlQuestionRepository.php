<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\QuestionOptionOverview;
use CodeLandQuiz\Model\QuestionOverview;
use CodeLandQuiz\Model\QuestionType;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use PDO;

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
        $statement = $this->connection()->prepare(
            self::FIND_OVERVIEW_BY_QUIZ_AND_ID_SQL,
        );
        $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        $statement->bindValue(':question_id', $questionId, PDO::PARAM_INT);
        $statement->execute();

        $questions = $this->mapRowsToQuestions($statement->fetchAll());

        return $questions[0] ?? null;
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
