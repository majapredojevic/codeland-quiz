<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\ParticipantType;

final readonly class JoinGameDTO
{
    public function __construct(
        public ParticipantType $participantType,
        public string $gamePin,
        public ?string $username,
        public string $nickname,
        public string $avatarKey,
    ) {
    }
}
