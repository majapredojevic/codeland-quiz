<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

enum QuizSort: string
{
    case RECENT = 'recent';
    case TITLE_ASC = 'titleAsc';
    case TITLE_DESC = 'titleDesc';
}
