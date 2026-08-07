<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class StudentStatisticsSummaryDTO
{
    public function __construct(
        public int $finishedSessionCount,
        public int $distinctQuizCount,
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
