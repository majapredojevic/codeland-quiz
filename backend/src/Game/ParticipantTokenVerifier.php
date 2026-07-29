<?php

declare(strict_types=1);

namespace CodeLandQuiz\Game;

use CodeLandQuiz\DTO\ParticipantTokenPayloadDTO;

interface ParticipantTokenVerifier
{
    public function verify(string $token): ParticipantTokenPayloadDTO;
}
