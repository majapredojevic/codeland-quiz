<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\DTO\StudentListQueryDTO;
use CodeLandQuiz\Model\StudentOverview;
use CodeLandQuiz\Model\StudentSort;
use CodeLandQuiz\Model\StudentStatusFilter;
use CodeLandQuiz\Student\Exception\StudentUsernameAlreadyExistsException;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final readonly class MySqlStudentRepository implements StudentRepository
{
    private const SELECT_OVERVIEW_SQL = <<<SQL
SELECT
    s.id,
    s.first_name,
    s.last_name,
    s.username,
    s.is_active,
    s.created_at,
    s.updated_at
FROM students s
SQL;

    private const COUNT_SQL = <<<SQL
SELECT COUNT(*)
FROM students s
SQL;

    private const FIND_OVERVIEW_BY_ID_SQL = <<<SQL
SELECT
    id,
    first_name,
    last_name,
    username,
    is_active,
    created_at,
    updated_at
FROM students
WHERE id = :student_id
  AND is_deleted = FALSE
LIMIT 1
SQL;

    private const INSERT_SQL = <<<SQL
INSERT INTO students (
    first_name,
    last_name,
    username,
    is_active,
    is_deleted
) VALUES (
    :first_name,
    :last_name,
    :username,
    TRUE,
    FALSE
)
SQL;

    public function __construct(
        private Database $database,
    ) {
    }

    /**
     * @return StudentOverview[]
     */
    public function findPage(StudentListQueryDTO $query): array
    {
        $sql = self::SELECT_OVERVIEW_SQL
            . $this->searchJoinClause($query->search)
            . $this->whereClause($query)
            . "\n"
            . $this->orderByClause($query->sort)
            . "\nLIMIT :limit\nOFFSET :offset";
        $statement = $this->connection()->prepare($sql);

        $this->bindSearch($statement, $query->search);
        $statement->bindValue(':limit', $query->pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', $query->getOffset(), PDO::PARAM_INT);
        $statement->execute();

        $students = [];

        while (($row = $statement->fetch()) !== false) {
            $students[] = $this->mapRowToStudentOverview($row);
        }

        return $students;
    }

    public function count(StudentListQueryDTO $query): int
    {
        $sql = self::COUNT_SQL
            . $this->searchJoinClause($query->search)
            . $this->whereClause($query);
        $statement = $this->connection()->prepare($sql);

        $this->bindSearch($statement, $query->search);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function findOverviewById(int $studentId): ?StudentOverview
    {
        $statement = $this->connection()->prepare(self::FIND_OVERVIEW_BY_ID_SQL);
        $statement->bindValue(':student_id', $studentId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToStudentOverview($row);
    }

    public function create(
        string $firstName,
        string $lastName,
        string $username,
    ): int {
        $statement = $this->connection()->prepare(self::INSERT_SQL);
        $statement->bindValue(':first_name', $firstName);
        $statement->bindValue(':last_name', $lastName);
        $statement->bindValue(':username', $username);

        try {
            $statement->execute();
        } catch (PDOException $exception) {
            $this->throwDuplicateUsernameIfNeeded($exception);

            throw $exception;
        }

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('Student was not created.');
        }

        $id = (int) $this->connection()->lastInsertId();

        if ($id < 1) {
            throw new RuntimeException('Student ID was not returned.');
        }

        return $id;
    }

    private function searchJoinClause(?string $search): string
    {
        if ($search === null) {
            return '';
        }

        return "\nCROSS JOIN (SELECT :search AS search_value) student_search";
    }

    private function whereClause(StudentListQueryDTO $query): string
    {
        $clause = "\nWHERE s.is_deleted = FALSE";

        if ($query->status === StudentStatusFilter::ACTIVE) {
            $clause .= "\n  AND s.is_active = TRUE";
        }

        if ($query->status === StudentStatusFilter::INACTIVE) {
            $clause .= "\n  AND s.is_active = FALSE";
        }

        if ($query->search !== null) {
            $clause .= "\n  AND (\n"
                . "      s.first_name LIKE student_search.search_value ESCAPE '\\\\'\n"
                . "      OR s.last_name LIKE student_search.search_value ESCAPE '\\\\'\n"
                . "      OR s.username LIKE student_search.search_value ESCAPE '\\\\'\n"
                . "      OR CONCAT(s.first_name, ' ', s.last_name) LIKE student_search.search_value ESCAPE '\\\\'\n"
                . "      OR CONCAT(s.last_name, ' ', s.first_name) LIKE student_search.search_value ESCAPE '\\\\'\n"
                . '  )';
        }

        return $clause;
    }

    private function orderByClause(StudentSort $sort): string
    {
        return match ($sort) {
            StudentSort::RECENT => 'ORDER BY s.created_at DESC, s.id DESC',
            StudentSort::NAME_ASC => 'ORDER BY s.last_name ASC, s.first_name ASC, s.id ASC',
            StudentSort::NAME_DESC => 'ORDER BY s.last_name DESC, s.first_name DESC, s.id DESC',
            StudentSort::USERNAME_ASC => 'ORDER BY s.username ASC, s.id ASC',
            StudentSort::USERNAME_DESC => 'ORDER BY s.username DESC, s.id DESC',
        };
    }

    private function bindSearch(PDOStatement $statement, ?string $search): void
    {
        if ($search === null) {
            return;
        }

        $statement->bindValue(
            ':search',
            '%' . $this->escapeLikePattern($search) . '%',
        );
    }

    private function escapeLikePattern(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value,
        );
    }

    private function throwDuplicateUsernameIfNeeded(
        PDOException $exception,
    ): void {
        $errorInfo = $exception->errorInfo;
        $sqlState = (string) ($errorInfo[0] ?? '');
        $driverCode = (int) ($errorInfo[1] ?? 0);
        $message = (string) ($errorInfo[2] ?? $exception->getMessage());

        if (
            $sqlState === '23000'
            && $driverCode === 1062
            && ($message === '' || str_contains($message, 'uq_students_username'))
        ) {
            throw new StudentUsernameAlreadyExistsException(
                'Student username is already in use.',
                0,
                $exception,
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToStudentOverview(array $row): StudentOverview
    {
        return new StudentOverview(
            id: (int) $row['id'],
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            username: (string) $row['username'],
            isActive: (bool) (int) $row['is_active'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    private function connection(): PDO
    {
        return $this->database->getConnection();
    }
}
