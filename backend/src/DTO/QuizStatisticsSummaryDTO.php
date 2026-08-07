<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class QuizStatisticsSummaryDTO
{
    public function __construct(
        public int $finishedSessionCount,
        public int $participantEntryCount,
        public int $registeredParticipationCount,
        public int $guestParticipationCount,
        public int $uniqueRegisteredStudentCount,
        public int $totalPossibleAnswerCount,
        public int $answerCount,
        public int $correctAnswerCount,
        public int $incorrectAnswerCount,
        public int $unansweredCount,
        public ?float $accuracyPercentage,
        public ?float $answerRatePercentage,
        public int $highestScore,
        public ?int $averageScore,
        public ?float $averageParticipantsPerSession,
    ) {
    }
}
