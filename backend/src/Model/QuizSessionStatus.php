<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

enum QuizSessionStatus: string
{
    case WAITING = 'WAITING';
    case ACTIVE = 'ACTIVE';
    case FINISHED = 'FINISHED';
}
