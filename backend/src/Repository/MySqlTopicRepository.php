<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\Topic;
use CodeLandQuiz\Model\TopicOverview;
use CodeLandQuiz\Model\TopicSort;
use CodeLandQuiz\Support\Database;
use CodeLandQuiz\Topic\Exception\TopicNameAlreadyExistsException;
use DateTimeImmutable;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final readonly class MySqlTopicRepository implements TopicRepository
{
    private const INSERT_SQL = <<<SQL
INSERT INTO topics (
    name,
    description,
    created_by,
    updated_by
) VALUES (
    :name,
    :description,
    :created_by,
    :updated_by
)
SQL;

    private const FIND_BY_ID_FOR_UPDATE_SQL = <<<SQL
SELECT
    id,
    name,
    description,
    created_by,
    updated_by,
    created_at,
    updated_at
FROM topics
WHERE id = :id
FOR UPDATE
SQL;

    private const UPDATE_SQL = <<<SQL
UPDATE topics
SET name = :name,
    description = :description,
    updated_by = :updated_by
WHERE id = :id
SQL;

    private const COUNT_NON_DELETED_QUIZZES_SQL = <<<SQL
SELECT COUNT(*)
FROM quizzes
WHERE topic_id = :topic_id
  AND is_deleted = FALSE
SQL;

    private const DELETE_SQL = <<<SQL
DELETE FROM topics
WHERE id = :id
SQL;

    private const SELECT_OVERVIEW_SQL = <<<SQL
SELECT
    t.id,
    t.name,
    t.description,
    t.created_by,
    creator.name AS created_by_name,
    t.updated_by,
    updater.name AS updated_by_name,
    t.created_at,
    t.updated_at,
    COUNT(q.id) AS quiz_count
FROM topics t
INNER JOIN users creator
    ON creator.id = t.created_by
INNER JOIN users updater
    ON updater.id = t.updated_by
LEFT JOIN quizzes q
    ON q.topic_id = t.id
   AND q.is_deleted = FALSE
SQL;

    private const GROUP_BY_SQL = <<<SQL
GROUP BY
    t.id,
    t.name,
    t.description,
    t.created_by,
    creator.name,
    t.updated_by,
    updater.name,
    t.created_at,
    t.updated_at
SQL;

    public function __construct(
        private Database $database,
    ) {
    }

    /**
     * @return TopicOverview[]
     */
    public function findPage(
        int $limit,
        int $offset,
        ?string $search,
        TopicSort $sort,
    ): array {
        $sql = self::SELECT_OVERVIEW_SQL
            . $this->searchClause($search)
            . "\n"
            . self::GROUP_BY_SQL
            . "\n"
            . $this->orderByClause($sort)
            . "\nLIMIT :limit\nOFFSET :offset";
        $statement = $this->connection()->prepare($sql);

        $this->bindSearch($statement, $search);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $topics = [];

        while (($row = $statement->fetch()) !== false) {
            $topics[] = $this->mapRowToTopicOverview($row);
        }

        return $topics;
    }

    public function count(?string $search): int
    {
        $sql = 'SELECT COUNT(*) FROM topics t' . $this->searchClause($search);
        $statement = $this->connection()->prepare($sql);

        $this->bindSearch($statement, $search);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function findOverviewById(int $id): ?TopicOverview
    {
        $sql = self::SELECT_OVERVIEW_SQL
            . "\nWHERE t.id = :id\n"
            . self::GROUP_BY_SQL
            . "\nLIMIT 1";
        $statement = $this->connection()->prepare($sql);
        $statement->execute([
            'id' => $id,
        ]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToTopicOverview($row);
    }

    public function create(
        string $name,
        ?string $description,
        int $actorUserId,
    ): int {
        $statement = $this->connection()->prepare(self::INSERT_SQL);
        $statement->bindValue(':name', $name);
        $this->bindNullableString($statement, ':description', $description);
        $statement->bindValue(':created_by', $actorUserId, PDO::PARAM_INT);
        $statement->bindValue(':updated_by', $actorUserId, PDO::PARAM_INT);

        try {
            $statement->execute();
        } catch (PDOException $exception) {
            $this->throwDuplicateTopicNameIfNeeded($exception);

            throw $exception;
        }

        $id = (int) $this->connection()->lastInsertId();

        if ($id < 1) {
            throw new RuntimeException('Topic ID was not returned.');
        }

        return $id;
    }

    public function findByIdForUpdate(int $id): ?Topic
    {
        $statement = $this->connection()->prepare(
            self::FIND_BY_ID_FOR_UPDATE_SQL,
        );
        $statement->execute([
            'id' => $id,
        ]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToTopic($row);
    }

    public function update(
        int $id,
        string $name,
        ?string $description,
        int $actorUserId,
    ): void {
        $statement = $this->connection()->prepare(self::UPDATE_SQL);
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':name', $name);
        $this->bindNullableString($statement, ':description', $description);
        $statement->bindValue(':updated_by', $actorUserId, PDO::PARAM_INT);

        try {
            $statement->execute();
        } catch (PDOException $exception) {
            $this->throwDuplicateTopicNameIfNeeded($exception);

            throw $exception;
        }
    }

    public function delete(int $id): void
    {
        $statement = $this->connection()->prepare(self::DELETE_SQL);
        $statement->execute([
            'id' => $id,
        ]);
    }

    public function countNonDeletedQuizzes(int $topicId): int
    {
        $statement = $this->connection()->prepare(
            self::COUNT_NON_DELETED_QUIZZES_SQL,
        );
        $statement->execute([
            'topic_id' => $topicId,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function searchClause(?string $search): string
    {
        if ($search === null) {
            return '';
        }

        return "\nWHERE (\n"
            . "    t.name LIKE :search_name\n"
            . "    OR t.description LIKE :search_description\n"
            . ')';
    }

    private function orderByClause(TopicSort $sort): string
    {
        return match ($sort) {
            TopicSort::RECENT => 'ORDER BY t.updated_at DESC, t.id DESC',
            TopicSort::NAME_ASC => 'ORDER BY t.name ASC, t.id ASC',
            TopicSort::NAME_DESC => 'ORDER BY t.name DESC, t.id DESC',
        };
    }

    private function bindSearch(PDOStatement $statement, ?string $search): void
    {
        if ($search === null) {
            return;
        }

        $likeSearch = '%' . $search . '%';
        $statement->bindValue(':search_name', $likeSearch);
        $statement->bindValue(':search_description', $likeSearch);
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

    private function throwDuplicateTopicNameIfNeeded(
        PDOException $exception,
    ): void {
        $errorInfo = $exception->errorInfo;
        $sqlState = (string) ($errorInfo[0] ?? '');
        $driverCode = (int) ($errorInfo[1] ?? 0);
        $message = (string) ($errorInfo[2] ?? $exception->getMessage());

        if (
            $sqlState === '23000'
            && $driverCode === 1062
            && str_contains($message, 'uq_topics_name')
        ) {
            throw new TopicNameAlreadyExistsException(
                'A topic with this name already exists.',
                0,
                $exception,
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToTopic(array $row): Topic
    {
        return new Topic(
            id: (int) $row['id'],
            name: (string) $row['name'],
            description: $row['description'] === null
                ? null
                : (string) $row['description'],
            createdById: (int) $row['created_by'],
            updatedById: (int) $row['updated_by'],
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            updatedAt: new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToTopicOverview(array $row): TopicOverview
    {
        return new TopicOverview(
            id: (int) $row['id'],
            name: (string) $row['name'],
            description: $row['description'] === null
                ? null
                : (string) $row['description'],
            quizCount: (int) $row['quiz_count'],
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
