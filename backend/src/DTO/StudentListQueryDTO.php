<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\StudentSort;
use CodeLandQuiz\Model\StudentStatusFilter;

final readonly class StudentListQueryDTO
{
    public function __construct(
        public int $pageIndex,
        public int $pageSize,
        public ?string $search,
        public StudentStatusFilter $status,
        public StudentSort $sort,
    ) {
    }

    public function getOffset(): int
    {
        return $this->pageIndex * $this->pageSize;
    }
}
