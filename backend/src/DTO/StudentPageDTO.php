<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class StudentPageDTO
{
    /**
     * @param StudentItemDTO[] $items
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
