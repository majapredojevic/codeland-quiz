<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

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
}
