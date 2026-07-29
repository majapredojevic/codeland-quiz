<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use CodeLandQuiz\Model\ParticipantType;

final readonly class AuthenticatedParticipantConnection
{
    public function __construct(
        public int $fileDescriptor,
        public string $connectionId,
        public int $participantId,
        public int $sessionId,
        public ParticipantType $participantType,
        public ?int $studentId,
    ) {
    }
}
