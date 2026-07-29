<?php

declare(strict_types=1);

namespace CodeLandQuiz\Game;

use CodeLandQuiz\DTO\IssuedParticipantTokenDTO;
use CodeLandQuiz\Model\SessionParticipantOverview;

interface ParticipantTokenIssuer
{
    public function issue(
        SessionParticipantOverview $participant,
    ): IssuedParticipantTokenDTO;
}
