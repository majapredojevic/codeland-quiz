<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class StudentStatisticsSessionQueryDTO
{
    public function __construct(
        public int $pageIndex,
        public int $pageSize,
        public ?int $quizId,
    ) {
    }

    public function getOffset(): int
    {
        return $this->pageIndex * $this->pageSize;
    }
}
