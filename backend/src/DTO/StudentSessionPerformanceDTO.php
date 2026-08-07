<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use DateTimeImmutable;

final readonly class StudentSessionPerformanceDTO
{
    public function __construct(
        public int $sessionId,
        public int $quizId,
        public string $quizTitle,
        public int $quizVersion,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $endedAt,
        public int $questionCount,
        public int $maxPossibleScore,
        public int $totalScore,
        public ?float $scorePercentage,
        public int $answerCount,
        public int $correctAnswerCount,
        public int $incorrectAnswerCount,
        public int $unansweredCount,
        public ?float $accuracyPercentage,
        public ?float $answerRatePercentage,
        public ?int $averageResponseTimeMs,
        public int $participantCount,
        public int $finalRank,
    ) {
    }
}
