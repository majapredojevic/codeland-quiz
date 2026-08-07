<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class StudentSessionPerformancePageDTO
{
    /**
     * @param StudentSessionPerformanceDTO[] $items
     */
    public function __construct(
        public array $items,
        public int $pageIndex,
        public int $pageSize,
        public int $totalItems,
        public int $totalPages,
    ) {
    }
}
