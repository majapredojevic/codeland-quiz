<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Support\Database;
use PDO;

final readonly class MySqlQuestionImageReferenceRepository implements
    QuestionImageReferenceRepository
{
    private const IS_REFERENCED_SQL = <<<SQL
SELECT (
    EXISTS (
        SELECT 1
        FROM questions
        WHERE image_path = :question_image_path
    )
    OR EXISTS (
        SELECT 1
        FROM session_questions
        WHERE image_path = :session_question_image_path
    )
) AS is_referenced
SQL;

    public function __construct(
        private Database $database,
    ) {
    }

    public function isReferenced(string $imagePath): bool
    {
        $statement = $this->database->getConnection()->prepare(
            self::IS_REFERENCED_SQL,
        );
        $statement->bindValue(
            ':question_image_path',
            $imagePath,
            PDO::PARAM_STR,
        );
        $statement->bindValue(
            ':session_question_image_path',
            $imagePath,
            PDO::PARAM_STR,
        );
        $statement->execute();

        return (bool) (int) $statement->fetchColumn();
    }
}
