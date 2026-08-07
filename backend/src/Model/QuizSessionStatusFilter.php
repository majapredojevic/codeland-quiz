<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

enum QuizSessionStatusFilter: string
{
    case ALL = 'ALL';
    case WAITING = 'WAITING';
    case ACTIVE = 'ACTIVE';
    case FINISHED = 'FINISHED';
}
