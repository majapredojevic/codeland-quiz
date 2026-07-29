<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\QuestionType;
use CodeLandQuiz\Model\SessionQuestionOptionOverview;
use CodeLandQuiz\Model\SessionQuestionOverview;
use CodeLandQuiz\Support\Database;
use PDO;

final readonly class MySqlSessionQuestionRepository implements SessionQuestionRepository
{
    private const FIND_BY_SESSION_AND_ORDER_SQL = <<<SQL
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
  AND sq.question_order = :question_order
ORDER BY sqo.option_order ASC, sqo.id ASC
SQL;

    public function __construct(
        private Database $database,
    ) {
    }

    public function findBySessionAndOrder(
        int $sessionId,
        int $questionOrder,
    ): ?SessionQuestionOverview {
        $statement = $this->connection()->prepare(
            self::FIND_BY_SESSION_AND_ORDER_SQL,
        );
        $statement->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $statement->bindValue(
            ':question_order',
            $questionOrder,
            PDO::PARAM_INT,
        );
        $statement->execute();

        $rows = $statement->fetchAll();

        if ($rows === []) {
            return null;
        }

        return $this->mapRowsToQuestion($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function mapRowsToQuestion(array $rows): SessionQuestionOverview
    {
        $firstRow = $rows[0];
        $options = [];

        foreach ($rows as $row) {
            if ($row['option_id'] === null) {
                continue;
            }

            $options[] = new SessionQuestionOptionOverview(
                id: (int) $row['option_id'],
                sessionQuestionId: (int) $row['question_id'],
                sourceOptionId: $row['source_option_id'] === null
                    ? null
                    : (int) $row['source_option_id'],
                optionText: (string) $row['option_text'],
                isCorrect: (bool) (int) $row['is_correct'],
                optionOrder: (int) $row['option_order'],
            );
        }

        return new SessionQuestionOverview(
            id: (int) $firstRow['question_id'],
            sessionId: (int) $firstRow['session_id'],
            sourceQuestionId: $firstRow['source_question_id'] === null
                ? null
                : (int) $firstRow['source_question_id'],
            questionText: (string) $firstRow['question_text'],
            questionType: QuestionType::from((string) $firstRow['question_type']),
            imagePath: $firstRow['image_path'] === null
                ? null
                : (string) $firstRow['image_path'],
            timeLimitSeconds: (int) $firstRow['time_limit_seconds'],
            maxPoints: (int) $firstRow['max_points'],
            questionOrder: (int) $firstRow['question_order'],
            options: $options,
        );
    }

    private function connection(): PDO
    {
        return $this->database->getConnection();
    }
}
