<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\ParticipantType;
use DateTimeImmutable;

final readonly class ParticipantTokenPayloadDTO
{
    public function __construct(
        public int $participantId,
        public int $sessionId,
        public ParticipantType $participantType,
        public ?int $studentId,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
        public string $jwtId,
    ) {
    }
}
