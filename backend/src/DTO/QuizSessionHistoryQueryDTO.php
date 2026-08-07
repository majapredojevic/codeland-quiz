<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\QuizSessionHistorySort;
use CodeLandQuiz\Model\QuizSessionStatusFilter;

final readonly class QuizSessionHistoryQueryDTO
{
    public function __construct(
        public int $pageIndex,
        public int $pageSize,
        public ?string $search,
        public QuizSessionStatusFilter $status,
        public ?int $quizId,
        public QuizSessionHistorySort $sort,
    ) {
    }

    public function getOffset(): int
    {
        return $this->pageIndex * $this->pageSize;
    }
}
