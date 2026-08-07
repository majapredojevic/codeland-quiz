<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class QuizSessionReportSummaryDTO
{
    public function __construct(
        public int $participantCount,
        public int $removedParticipantCount,
        public int $totalAnswerCount,
        public int $totalCorrectAnswerCount,
        public int $highestScore,
        public ?int $averageScore,
    ) {
    }
}
