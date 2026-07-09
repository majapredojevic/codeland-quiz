<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\LoginAttempt;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class MySqlLoginAttemptRepository implements LoginAttemptRepository
{
    private const INSERT_SQL = <<<SQL
INSERT INTO login_attempts (
    email,
    successful,
    user_agent,
    attempted_at
) VALUES (
    :email,
    :successful,
    :user_agent,
    :attempted_at
)
SQL;

    private const COUNT_FAILED_SINCE_SQL = <<<SQL
SELECT COUNT(*)
FROM login_attempts
WHERE email = :email
  AND successful = FALSE
  AND attempted_at >= :since
SQL;

    private const CLEAR_ATTEMPTS_SQL = <<<SQL
DELETE FROM login_attempts
WHERE email = :email
SQL;

    public function __construct(
        private readonly Database $database,
    ) {
    }

    public function save(LoginAttempt $attempt): void
    {
        $statement = $this->connection()->prepare(self::INSERT_SQL);

        $statement->execute([
            'email' => $attempt->getEmail(),
            'successful' => $attempt->isSuccessful() ? 1 : 0,
            'user_agent' => $attempt->getUserAgent(),
            'attempted_at' => $this->formatDateTime($attempt->getAttemptedAt()),
        ]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('Login attempt was not inserted.');
        }
    }

    public function countFailedAttemptsSince(
        string $email,
        DateTimeImmutable $since,
    ): int {
        $statement = $this->connection()->prepare(self::COUNT_FAILED_SINCE_SQL);

        $statement->execute([
            'email' => $email,
            'since' => $this->formatDateTime($since),
        ]);

        return (int) $statement->fetchColumn();
    }

    public function clearAttempts(string $email): void
    {
        $statement = $this->connection()->prepare(self::CLEAR_ATTEMPTS_SQL);

        $result = $statement->execute([
            'email' => $email,
        ]);

        if ($result === false) {
            throw new RuntimeException('Login attempts were not cleared.');
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
