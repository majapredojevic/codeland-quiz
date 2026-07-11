<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\NewUser;
use CodeLandQuiz\Model\User;
use CodeLandQuiz\Model\UserRole;
use CodeLandQuiz\Support\Database;
use PDO;
use RuntimeException;

final class MySqlUserRepository implements UserRepository
{
    private const INSERT_SQL = <<<SQL
INSERT INTO users (
    name,
    email,
    password_hash,
    must_change_password,
    role,
    is_active
) VALUES (
    :name,
    :email,
    :password_hash,
    :must_change_password,
    :role,
    :is_active
)
SQL;

    private const FIND_BY_ID_SQL = <<<SQL
SELECT id, name, email, password_hash, must_change_password, role, is_active
FROM users
WHERE id = :id
  AND is_deleted = FALSE
  AND deleted_at IS NULL
LIMIT 1
SQL;

    private const FIND_BY_EMAIL_SQL = <<<SQL
SELECT id, name, email, password_hash, must_change_password, role, is_active
FROM users
WHERE email = :email
  AND is_active = TRUE
  AND is_deleted = FALSE
  AND deleted_at IS NULL
LIMIT 1
SQL;

    private const FIND_BY_EMAIL_INCLUDING_INACTIVE_SQL = <<<SQL
SELECT id, name, email, password_hash, must_change_password, role, is_active
FROM users
WHERE email = :email
  AND is_deleted = FALSE
  AND deleted_at IS NULL
LIMIT 1
SQL;

    private const FIND_TEACHER_BY_ID_SQL = <<<SQL
SELECT id, name, email, password_hash, must_change_password, role, is_active
FROM users
WHERE id = :id
  AND role = 'TEACHER'
  AND is_deleted = FALSE
  AND deleted_at IS NULL
LIMIT 1
SQL;

    private const FIND_TEACHERS_PAGE_SQL = <<<SQL
SELECT id, name, email, password_hash, must_change_password, role, is_active
FROM users
WHERE role = 'TEACHER'
  AND is_deleted = FALSE
  AND deleted_at IS NULL
ORDER BY name ASC, id ASC
LIMIT :limit
OFFSET :offset
SQL;

    private const COUNT_TEACHERS_SQL = <<<SQL
SELECT COUNT(*)
FROM users
WHERE role = 'TEACHER'
  AND is_deleted = FALSE
  AND deleted_at IS NULL
SQL;

    private const UPDATE_PASSWORD_STATE_SQL = <<<SQL
UPDATE users
SET password_hash = :password_hash,
    must_change_password = :must_change_password
WHERE id = :id
  AND is_deleted = FALSE
  AND deleted_at IS NULL
SQL;

    private const UPDATE_TEACHER_PROFILE_SQL = <<<SQL
UPDATE users
SET name = :name,
    email = :email
WHERE id = :id
  AND role = 'TEACHER'
  AND is_deleted = FALSE
  AND deleted_at IS NULL
SQL;

    public function __construct(
        private readonly Database $database,
    ) {}

    public function create(NewUser $user): int
    {
        $statement = $this->connection()->prepare(self::INSERT_SQL);

        $statement->execute([
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'password_hash' => $user->getPasswordHash(),
            'must_change_password' => $user->mustChangePassword() ? 1 : 0,
            'role' => $user->getRole()->value,
            'is_active' => $user->isActive() ? 1 : 0,
        ]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('User was not created.');
        }

        $id = (int) $this->connection()->lastInsertId();

        if ($id < 1) {
            throw new RuntimeException('User ID was not returned.');
        }

        return $id;
    }

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

    public function findTeacherById(int $id): ?User
    {
        $statement = $this->connection()->prepare(
            self::FIND_TEACHER_BY_ID_SQL,
        );

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

    public function findByEmailIncludingInactive(string $email): ?User
    {
        $statement = $this->connection()->prepare(
            self::FIND_BY_EMAIL_INCLUDING_INACTIVE_SQL,
        );

        $statement->execute([
            'email' => $email,
        ]);

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToUser($row);
    }

    /**
     * @return User[]
     */
    public function findTeachersPage(
        int $limit,
        int $offset,
    ): array
    {
        $statement = $this->connection()->prepare(self::FIND_TEACHERS_PAGE_SQL);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $users = [];

        while (($row = $statement->fetch()) !== false) {
            $users[] = $this->mapRowToUser($row);
        }

        return $users;
    }

    public function countTeachers(): int
    {
        $statement = $this->connection()->prepare(self::COUNT_TEACHERS_SQL);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function save(User $user): void
    {
        $statement = $this->connection()->prepare(self::UPDATE_PASSWORD_STATE_SQL);

        $statement->execute([
            'id' => $user->getId(),
            'password_hash' => $user->getPasswordHash(),
            'must_change_password' => $user->mustChangePassword() ? 1 : 0,
        ]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('User password hash was not updated.');
        }
    }

    public function updateTeacherProfile(User $user): void
    {
        $statement = $this->connection()->prepare(self::UPDATE_TEACHER_PROFILE_SQL);

        $statement->execute([
            'id' => $user->getId(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
        ]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('Teacher profile was not updated.');
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
            mustChangePassword: (bool) (int) $row['must_change_password'],
            role: UserRole::from((string) $row['role']),
            isActive: (bool) (int) $row['is_active'],
        );
    }

    private function connection(): PDO
    {
        return $this->database->getConnection();
    }
}
