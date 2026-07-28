<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\Quiz;
use CodeLandQuiz\Model\QuizOverview;
use CodeLandQuiz\Model\QuizSort;
use CodeLandQuiz\Model\QuizStatusFilter;
use CodeLandQuiz\Quiz\Exception\QuizTitleVersionAlreadyExistsException;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final readonly class MySqlQuizRepository implements QuizRepository
{
    private const SELECT_OVERVIEW_SQL = <<<SQL
SELECT
    q.id,
    q.title,
    q.version,
    q.description,
    q.is_active,
    q.topic_id,
    t.name AS topic_name,
    q.created_by,
    creator.name AS created_by_name,
    q.updated_by,
    updater.name AS updated_by_name,
    q.created_at,
    q.updated_at,
    (
        SELECT COUNT(*)
        FROM questions question_count
        WHERE question_count.quiz_id = q.id
          AND question_count.is_deleted = FALSE
    ) AS question_count
FROM quizzes q
LEFT JOIN topics t
    ON t.id = q.topic_id
INNER JOIN users creator
    ON creator.id = q.created_by
INNER JOIN users updater
    ON updater.id = q.updated_by
WHERE q.is_deleted = FALSE
SQL;

    private const COUNT_SQL = <<<SQL
SELECT COUNT(*)
FROM quizzes q
WHERE q.is_deleted = FALSE
SQL;

    private const INSERT_SQL = <<<SQL
INSERT INTO quizzes (
    topic_id,
    created_by,
    updated_by,
    title,
    version,
    description,
    is_active,
    is_deleted
) VALUES (
    :topic_id,
    :created_by,
    :updated_by,
    :title,
    :version,
    :description,
    FALSE,
    FALSE
)
SQL;

    private const FIND_BY_ID_FOR_UPDATE_SQL = <<<SQL
SELECT
    id,
    topic_id,
    created_by,
    updated_by,
    title,
    version,
    description,
    is_active,
    is_deleted,
    created_at,
    updated_at,
    deleted_at
FROM quizzes
WHERE id = :id
  AND is_deleted = FALSE
FOR UPDATE
SQL;

    private const UPDATE_SQL = <<<SQL
UPDATE quizzes
SET topic_id = :topic_id,
    title = :title,
    version = :version,
    description = :description,
    updated_by = :updated_by
WHERE id = :id
  AND is_deleted = FALSE
SQL;

    private const HAS_OPEN_SESSIONS_SQL = <<<SQL
SELECT EXISTS (
    SELECT 1
    FROM quiz_sessions
    WHERE quiz_id = :quiz_id
      AND status IN ('WAITING', 'ACTIVE')
) AS has_open_sessions
SQL;

    private const SOFT_DELETE_SQL = <<<SQL
UPDATE quizzes
SET is_deleted = TRUE,
    is_active = FALSE,
    deleted_at = CURRENT_TIMESTAMP,
    updated_by = :updated_by
WHERE id = :id
  AND is_deleted = FALSE
SQL;

    private const TOUCH_SQL = <<<SQL
UPDATE quizzes
SET updated_by = :updated_by,
    updated_at = CURRENT_TIMESTAMP
WHERE id = :id
  AND is_deleted = FALSE
SQL;

    private const UPDATE_ACTIVE_STATUS_SQL = <<<SQL
UPDATE quizzes
SET is_active = :is_active,
    updated_by = :updated_by,
    updated_at = CURRENT_TIMESTAMP
WHERE id = :quiz_id
  AND is_deleted = FALSE
SQL;

    public function __construct(
        private Database $database,
    ) {
    }

    /**
     * @return QuizOverview[]
     */
    public function findPage(
        int $limit,
        int $offset,
        ?string $search,
        ?int $topicId,
        QuizStatusFilter $status,
        QuizSort $sort,
    ): array {
        $sql = self::SELECT_OVERVIEW_SQL
            . $this->filterClause($search, $topicId, $status)
            . "\n"
            . $this->orderByClause($sort)
            . "\nLIMIT :limit\nOFFSET :offset";
        $statement = $this->connection()->prepare($sql);

        $this->bindFilters($statement, $search, $topicId);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $quizzes = [];

        while (($row = $statement->fetch()) !== false) {
            $quizzes[] = $this->mapRowToQuizOverview($row);
        }

        return $quizzes;
    }

    public function count(
        ?string $search,
        ?int $topicId,
        QuizStatusFilter $status,
    ): int {
        $sql = self::COUNT_SQL . $this->filterClause($search, $topicId, $status);
        $statement = $this->connection()->prepare($sql);

        $this->bindFilters($statement, $search, $topicId);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function findOverviewById(int $id): ?QuizOverview
    {
        $sql = self::SELECT_OVERVIEW_SQL . "\nAND q.id = :id\nLIMIT 1";
        $statement = $this->connection()->prepare($sql);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToQuizOverview($row);
    }

    public function create(
        string $title,
        int $version,
        ?string $description,
        ?int $topicId,
        int $actorUserId,
    ): int {
        $statement = $this->connection()->prepare(self::INSERT_SQL);
        $this->bindNullableInt($statement, ':topic_id', $topicId);
        $statement->bindValue(':created_by', $actorUserId, PDO::PARAM_INT);
        $statement->bindValue(':updated_by', $actorUserId, PDO::PARAM_INT);
        $statement->bindValue(':title', $title);
        $statement->bindValue(':version', $version, PDO::PARAM_INT);
        $this->bindNullableString($statement, ':description', $description);

        try {
            $statement->execute();
        } catch (PDOException $exception) {
            $this->throwDuplicateQuizTitleVersionIfNeeded($exception);

            throw $exception;
        }

        $id = (int) $this->connection()->lastInsertId();

        if ($id < 1) {
            throw new RuntimeException('Quiz ID was not returned.');
        }

        return $id;
    }

    public function findByIdForUpdate(int $id): ?Quiz
    {
        $statement = $this->connection()->prepare(
            self::FIND_BY_ID_FOR_UPDATE_SQL,
        );
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToQuiz($row);
    }

    public function update(
        int $id,
        string $title,
        int $version,
        ?string $description,
        ?int $topicId,
        int $actorUserId,
    ): void {
        $statement = $this->connection()->prepare(self::UPDATE_SQL);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $this->bindNullableInt($statement, ':topic_id', $topicId);
        $statement->bindValue(':title', $title);
        $statement->bindValue(':version', $version, PDO::PARAM_INT);
        $this->bindNullableString($statement, ':description', $description);
        $statement->bindValue(':updated_by', $actorUserId, PDO::PARAM_INT);

        try {
            $statement->execute();
        } catch (PDOException $exception) {
            $this->throwDuplicateQuizTitleVersionIfNeeded($exception);

            throw $exception;
        }
    }

    public function softDelete(
        int $id,
        int $actorUserId,
    ): void {
        $statement = $this->connection()->prepare(self::SOFT_DELETE_SQL);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':updated_by', $actorUserId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function hasOpenSessions(int $quizId): bool
    {
        $statement = $this->connection()->prepare(self::HAS_OPEN_SESSIONS_SQL);
        $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        $statement->execute();

        return (bool) (int) $statement->fetchColumn();
    }

    public function touch(
        int $quizId,
        int $actorUserId,
    ): void {
        $statement = $this->connection()->prepare(self::TOUCH_SQL);
        $statement->bindValue(':id', $quizId, PDO::PARAM_INT);
        $statement->bindValue(':updated_by', $actorUserId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function updateActiveStatus(
        int $quizId,
        bool $isActive,
        int $actorUserId,
    ): void {
        $statement = $this->connection()->prepare(
            self::UPDATE_ACTIVE_STATUS_SQL,
        );
        $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        $statement->bindValue(':is_active', $isActive ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue(':updated_by', $actorUserId, PDO::PARAM_INT);
        $statement->execute();
    }

    private function filterClause(
        ?string $search,
        ?int $topicId,
        QuizStatusFilter $status,
    ): string {
        $clause = '';

        if ($search !== null) {
            $clause .= "\nAND (\n"
                . "    q.title LIKE :search_title\n"
                . "    OR q.description LIKE :search_description\n"
                . ')';
        }

        if ($topicId !== null) {
            $clause .= "\nAND q.topic_id = :topic_id";
        }

        if ($status === QuizStatusFilter::ACTIVE) {
            $clause .= "\nAND q.is_active = TRUE";
        }

        if ($status === QuizStatusFilter::INACTIVE) {
            $clause .= "\nAND q.is_active = FALSE";
        }

        return $clause;
    }

    private function orderByClause(QuizSort $sort): string
    {
        return match ($sort) {
            QuizSort::RECENT => 'ORDER BY q.updated_at DESC, q.id DESC',
            QuizSort::TITLE_ASC => 'ORDER BY q.title ASC, q.version ASC, q.id ASC',
            QuizSort::TITLE_DESC => 'ORDER BY q.title DESC, q.version DESC, q.id DESC',
        };
    }

    private function bindFilters(
        PDOStatement $statement,
        ?string $search,
        ?int $topicId,
    ): void {
        if ($search !== null) {
            $likeSearch = '%' . $search . '%';
            $statement->bindValue(':search_title', $likeSearch);
            $statement->bindValue(':search_description', $likeSearch);
        }

        if ($topicId !== null) {
            $statement->bindValue(':topic_id', $topicId, PDO::PARAM_INT);
        }
    }

    private function bindNullableInt(
        PDOStatement $statement,
        string $parameter,
        ?int $value,
    ): void {
        if ($value === null) {
            $statement->bindValue($parameter, null, PDO::PARAM_NULL);

            return;
        }

        $statement->bindValue($parameter, $value, PDO::PARAM_INT);
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

    private function throwDuplicateQuizTitleVersionIfNeeded(
        PDOException $exception,
    ): void {
        $errorInfo = $exception->errorInfo;
        $sqlState = (string) ($errorInfo[0] ?? '');
        $driverCode = (int) ($errorInfo[1] ?? 0);

        if ($sqlState === '23000' && $driverCode === 1062) {
            throw new QuizTitleVersionAlreadyExistsException(
                'A quiz with this title and version already exists.',
                0,
                $exception,
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToQuiz(array $row): Quiz
    {
        return new Quiz(
            id: (int) $row['id'],
            topicId: $row['topic_id'] === null
                ? null
                : (int) $row['topic_id'],
            createdById: (int) $row['created_by'],
            updatedById: (int) $row['updated_by'],
            title: (string) $row['title'],
            version: (int) $row['version'],
            description: $row['description'] === null
                ? null
                : (string) $row['description'],
            isActive: (bool) (int) $row['is_active'],
            isDeleted: (bool) (int) $row['is_deleted'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
            deletedAt: $row['deleted_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['deleted_at']),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToQuizOverview(array $row): QuizOverview
    {
        return new QuizOverview(
            id: (int) $row['id'],
            title: (string) $row['title'],
            version: (int) $row['version'],
            description: $row['description'] === null
                ? null
                : (string) $row['description'],
            isActive: (bool) (int) $row['is_active'],
            questionCount: (int) $row['question_count'],
            topicId: $row['topic_id'] === null
                ? null
                : (int) $row['topic_id'],
            topicName: $row['topic_name'] === null
                ? null
                : (string) $row['topic_name'],
            createdById: (int) $row['created_by'],
            createdByName: (string) $row['created_by_name'],
            updatedById: (int) $row['updated_by'],
            updatedByName: (string) $row['updated_by_name'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    private function connection(): PDO
    {
        return $this->database->getConnection();
    }
}
