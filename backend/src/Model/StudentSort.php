<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

enum StudentSort: string
{
    case RECENT = 'RECENT';
    case NAME_ASC = 'NAME_ASC';
    case NAME_DESC = 'NAME_DESC';
    case USERNAME_ASC = 'USERNAME_ASC';
    case USERNAME_DESC = 'USERNAME_DESC';
}
