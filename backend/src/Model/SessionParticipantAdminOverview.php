<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

use DateTimeImmutable;

final readonly class SessionParticipantAdminOverview
{
    public function __construct(
        public int $id,
        public int $sessionId,
        public ParticipantType $participantType,
        public ?int $studentId,
        public ?string $studentFirstName,
        public ?string $studentLastName,
        public ?string $studentUsername,
        public string $nickname,
        public string $avatarKey,
        public int $totalScore,
        public bool $isConnected,
        public ?DateTimeImmutable $disconnectedAt,
        public DateTimeImmutable $joinedAt,
        public bool $hasAnsweredCurrentQuestion,
    ) {
    }
}
