<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\DTO\QuizSessionHistoryQueryDTO;
use CodeLandQuiz\Model\QuizSessionHistoryOverview;

interface QuizSessionHistoryRepository
{
    /**
     * @return QuizSessionHistoryOverview[]
     */
    public function findPage(
        QuizSessionHistoryQueryDTO $query,
    ): array;

    public function count(
        QuizSessionHistoryQueryDTO $query,
    ): int;
}
