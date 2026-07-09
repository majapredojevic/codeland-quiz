<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\User;
use CodeLandQuiz\Model\UserRole;
use CodeLandQuiz\Support\Database;
use PDO;
use RuntimeException;

final class MySqlUserRepository implements UserRepository
{
    private const FIND_BY_ID_SQL = <<<SQL
SELECT id, name, email, password_hash, role, is_active
FROM users
WHERE id = :id
  AND is_deleted = FALSE
  AND deleted_at IS NULL
LIMIT 1
SQL;

    private const FIND_BY_EMAIL_SQL = <<<SQL
SELECT id, name, email, password_hash, role, is_active
FROM users
WHERE email = :email
  AND is_active = TRUE
  AND is_deleted = FALSE
  AND deleted_at IS NULL
LIMIT 1
SQL;

    private const UPDATE_PASSWORD_HASH_SQL = <<<SQL
UPDATE users
SET password_hash = :password_hash
WHERE id = :id
  AND is_deleted = FALSE
  AND deleted_at IS NULL
SQL;

    public function __construct(
        private readonly Database $database,
    ) {}

    public function findById(int $id): ?User
    {
        $statement = $this->connection()->prepare(self::FIND_BY_ID_SQL);

        $statement->execute([
            'id' => $id,
        ]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToUser($row);
    }

    public function findByEmail(string $email): ?User
    {
        $statement = $this->connection()->prepare(self::FIND_BY_EMAIL_SQL);

        $statement->execute([
            'email' => $email,
        ]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToUser($row);
    }

    public function save(User $user): void
    {
        $statement = $this->connection()->prepare(self::UPDATE_PASSWORD_HASH_SQL);

        $statement->execute([
            'id' => $user->getId(),
            'password_hash' => $user->getPasswordHash(),
        ]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('User password hash was not updated.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToUser(array $row): User
    {
        return new User(
            id: (int) $row['id'],
            name: (string) $row['name'],
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
            role: UserRole::from((string) $row['role']),
            isActive: (bool) (int) $row['is_active'],
        );
    }

    private function connection(): PDO
    {
        return $this->database->getConnection();
    }
}
