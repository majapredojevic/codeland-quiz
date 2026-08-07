<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use DateTimeImmutable;

final readonly class SessionReportParticipantAnswerDTO
{
    /**
     * @param int[] $selectedOptionIds
     */
    public function __construct(
        public int $sessionQuestionId,
        public int $questionOrder,
        public bool $answered,
        public array $selectedOptionIds,
        public ?bool $isCorrect,
        public ?int $responseTimeMs,
        public int $pointsAwarded,
        public ?DateTimeImmutable $answeredAt,
    ) {
    }
}
