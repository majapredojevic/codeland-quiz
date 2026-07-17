<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\TopicSort;

final readonly class ListTopicsDTO
{
    public function __construct(
        public int $pageIndex,
        public int $pageSize,
        public ?string $search,
        public TopicSort $sort,
    ) {
    }

    public function getOffset(): int
    {
        return $this->pageIndex * $this->pageSize;
    }
}
