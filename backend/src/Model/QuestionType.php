<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

enum QuestionType: string
{
    case TRUE_FALSE = 'TRUE_FALSE';
    case SINGLE_CHOICE = 'SINGLE_CHOICE';
    case MULTIPLE_CHOICE = 'MULTIPLE_CHOICE';
}
