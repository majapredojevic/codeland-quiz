<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class ListTeachersDTO
{
    public function __construct(
        public int $pageIndex,
        public int $pageSize,
    ) {
    }

    public function getOffset(): int
    {
        return $this->pageIndex * $this->pageSize;
    }
}
