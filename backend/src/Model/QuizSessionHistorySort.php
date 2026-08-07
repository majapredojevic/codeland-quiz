<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

enum QuizSessionHistorySort: string
{
    case RECENT = 'RECENT';
    case OLDEST = 'OLDEST';
    case QUIZ_TITLE_ASC = 'QUIZ_TITLE_ASC';
    case QUIZ_TITLE_DESC = 'QUIZ_TITLE_DESC';
}
