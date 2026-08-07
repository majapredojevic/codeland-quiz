<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class QuizStatisticsDTO
{
    /**
     * @param QuizQuestionStatisticsDTO[] $questions
     */
    public function __construct(
        public int $quizId,
        public string $quizTitle,
        public int $quizVersion,
        public QuizStatisticsSummaryDTO $summary,
        public array $questions,
    ) {
    }
}
