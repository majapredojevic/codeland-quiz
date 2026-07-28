<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

enum StudentStatusFilter: string
{
    case ALL = 'ALL';
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
}
