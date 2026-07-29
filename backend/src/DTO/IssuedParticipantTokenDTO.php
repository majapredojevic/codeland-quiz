<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use DateTimeImmutable;

final readonly class IssuedParticipantTokenDTO
{
    public function __construct(
        public string $token,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
