<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

use DateTimeImmutable;

final readonly class SessionQuestionParticipantResultOverview
{
    /**
     * @param int[]|null $selectedOptionIds
     */
    public function __construct(
        public int $participantId,
        public ParticipantType $participantType,
        public string $nickname,
        public string $avatarKey,
        public int $totalScore,
        public DateTimeImmutable $joinedAt,
        public ?array $selectedOptionIds,
        public ?bool $isCorrect,
        public ?int $responseTimeMs,
        public int $pointsAwarded,
        public ?DateTimeImmutable $answeredAt,
    ) {
    }
}
