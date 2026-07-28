<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

enum QuizStatusFilter: string
{
    case ALL = 'all';
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
