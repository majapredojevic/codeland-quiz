<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class StudentQuizStatisticsDTO
{
    public function __construct(
        public int $quizId,
        public string $quizTitle,
        public int $quizVersion,
        public int $finishedSessionCount,
        public int $totalPossibleAnswerCount,
        public int $answerCount,
        public int $correctAnswerCount,
        public int $incorrectAnswerCount,
        public int $unansweredCount,
        public ?float $accuracyPercentage,
        public ?float $answerRatePercentage,
        public int $totalScore,
        public ?int $averageScore,
        public ?float $averageScorePercentage,
        public int $highestScore,
        public ?float $highestScorePercentage,
        public ?int $averageResponseTimeMs,
        public int $topThreeCount,
        public int $firstPlaceCount,
    ) {
    }
}
