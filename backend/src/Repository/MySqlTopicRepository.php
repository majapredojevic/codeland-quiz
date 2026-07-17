<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\TopicOverview;
use CodeLandQuiz\Model\TopicSort;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use PDO;
use PDOStatement;

final readonly class MySqlTopicRepository implements TopicRepository
{
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
