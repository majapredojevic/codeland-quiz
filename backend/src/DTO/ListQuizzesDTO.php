<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\QuizSort;
use CodeLandQuiz\Model\QuizStatusFilter;

final readonly class ListQuizzesDTO
{
    public function __construct(
        public int $pageIndex,
        public int $pageSize,
        public ?string $search,
        public ?int $topicId,
        public QuizStatusFilter $status,
        public QuizSort $sort,
    ) {
    }

    public function getOffset(): int
    {
        return $this->pageIndex * $this->pageSize;
    }
}
