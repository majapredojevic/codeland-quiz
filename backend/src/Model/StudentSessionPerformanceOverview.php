<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

use DateTimeImmutable;

final readonly class StudentSessionPerformanceOverview
{
    public function __construct(
        public int $sessionId,
        public int $quizId,
        public string $quizTitle,
        public int $quizVersion,
        public int $participantId,
        public DateTimeImmutable $sessionStartedAt,
        public DateTimeImmutable $sessionEndedAt,
        public int $questionCount,
        public int $maxPossibleScore,
        public int $totalScore,
        public int $answerCount,
        public int $correctAnswerCount,
        public int $totalResponseTimeMs,
        public int $participantCount,
        public int $finalRank,
    ) {
    }
}
