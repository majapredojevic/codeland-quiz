<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

enum TopicSort: string
{
    case RECENT = 'recent';
    case NAME_ASC = 'nameAsc';
    case NAME_DESC = 'nameDesc';
}
