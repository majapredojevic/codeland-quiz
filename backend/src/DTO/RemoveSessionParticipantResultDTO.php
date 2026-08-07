<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class RemoveSessionParticipantResultDTO
{
    public function __construct(
        public int $sessionId,
        public int $participantId,
        public bool $stateChanged,
    ) {
    }
}
