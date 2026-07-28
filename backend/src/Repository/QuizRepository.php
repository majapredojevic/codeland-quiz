<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\QuizOverview;
use CodeLandQuiz\Model\QuizSort;
use CodeLandQuiz\Model\QuizStatusFilter;

interface QuizRepository
{
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
    ): array;

    public function count(
        ?string $search,
        ?int $topicId,
        QuizStatusFilter $status,
    ): int;

    public function findOverviewById(int $id): ?QuizOverview;
}
