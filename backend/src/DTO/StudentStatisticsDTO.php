<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class StudentStatisticsDTO
{
    /**
     * @param StudentQuizStatisticsDTO[] $quizzes
     */
    public function __construct(
        public StudentItemDTO $student,
        public StudentStatisticsSummaryDTO $summary,
        public array $quizzes,
    ) {
    }
}
