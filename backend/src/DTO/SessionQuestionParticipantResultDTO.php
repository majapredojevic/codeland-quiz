<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\ParticipantType;
use DateTimeImmutable;

final readonly class SessionQuestionParticipantResultDTO
{
    /**
     * @param int[] $selectedOptionIds
     */
    public function __construct(
        public int $participantId,
        public ParticipantType $participantType,
        public string $nickname,
        public string $avatarKey,
        public bool $answered,
        public array $selectedOptionIds,
        public ?bool $isCorrect,
        public ?int $responseTimeMs,
        public int $pointsAwarded,
        public int $totalScore,
        public ?DateTimeImmutable $answeredAt,
    ) {
    }
}
