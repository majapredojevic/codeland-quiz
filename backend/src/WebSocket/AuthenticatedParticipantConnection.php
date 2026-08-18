<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use CodeLandQuiz\Model\ParticipantType;
use DateTimeImmutable;

final class AuthenticatedParticipantConnection
{
    private int $lastSeenMonotonicNanoseconds;

    public function __construct(
        public readonly int $fileDescriptor,
        public readonly string $connectionId,
        public readonly int $participantId,
        public readonly int $sessionId,
        public readonly ParticipantType $participantType,
        public readonly ?int $studentId,
        public readonly DateTimeImmutable $participantTokenExpiresAt,
        ?int $lastSeenMonotonicNanoseconds = null,
    ) {
        $this->lastSeenMonotonicNanoseconds =
            $lastSeenMonotonicNanoseconds ?? hrtime(true);
    }

    public function tokenHasExpired(int $currentTimestamp): bool
    {
        return $this->participantTokenExpiresAt->getTimestamp()
            <= $currentTimestamp;
    }

    public function touch(int $monotonicNanoseconds): void
    {
        $this->lastSeenMonotonicNanoseconds = $monotonicNanoseconds;
    }

    public function idleNanoseconds(int $monotonicNanoseconds): int
    {
        return max(
            0,
            $monotonicNanoseconds - $this->lastSeenMonotonicNanoseconds,
        );
    }
}
