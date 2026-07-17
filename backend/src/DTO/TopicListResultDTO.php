<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class TopicListResultDTO
{
    /**
     * @param TopicItemDTO[] $topics
     */
    public function __construct(
        public array $topics,
        public int $pageIndex,
        public int $pageSize,
        public int $totalItems,
        public int $totalPages,
    ) {
    }
}
