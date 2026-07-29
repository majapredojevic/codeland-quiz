<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

enum ParticipantType: string
{
    case REGISTERED = 'REGISTERED';
    case GUEST = 'GUEST';
}
