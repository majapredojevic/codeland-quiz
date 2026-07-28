<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class QuizListResultDTO
{
    /**
     * @param QuizItemDTO[] $quizzes
     */
    public function __construct(
        public array $quizzes,
        public int $pageIndex,
        public int $pageSize,
        public int $totalItems,
        public int $totalPages,
    ) {
    }
}
