<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

use DateTimeImmutable;

final readonly class SessionParticipantOverview
{
    public function __construct(
        public int $id,
        public int $sessionId,
        public ParticipantType $participantType,
        public ?int $studentId,
        public string $nickname,
        public string $avatarKey,
        public int $totalScore,
        public bool $isConnected,
        public bool $isRemoved,
        public DateTimeImmutable $joinedAt,
    ) {
    }
}
