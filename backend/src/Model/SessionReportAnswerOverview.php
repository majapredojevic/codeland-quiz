<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

use DateTimeImmutable;

final readonly class SessionReportAnswerOverview
{
    /**
     * @param int[] $selectedOptionIds
     */
    public function __construct(
        public int $participantId,
        public int $sessionQuestionId,
        public array $selectedOptionIds,
        public bool $isCorrect,
        public int $responseTimeMs,
        public int $pointsAwarded,
        public DateTimeImmutable $answeredAt,
    ) {
    }
}
