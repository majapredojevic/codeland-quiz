<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\Topic;
use CodeLandQuiz\Model\TopicOverview;
use CodeLandQuiz\Model\TopicSort;

interface TopicRepository
{
    /**
     * @return TopicOverview[]
     */
    public function findPage(
        int $limit,
        int $offset,
        ?string $search,
        TopicSort $sort,
    ): array;

    public function count(?string $search): int;

    public function findOverviewById(int $id): ?TopicOverview;

    public function create(
        string $name,
        ?string $description,
        int $actorUserId,
    ): int;

    public function findByIdForUpdate(int $id): ?Topic;

    public function update(
        int $id,
        string $name,
        ?string $description,
        int $actorUserId,
    ): void;

    public function delete(int $id): void;

    public function countNonDeletedQuizzes(int $topicId): int;
}
