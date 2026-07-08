<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

enum UserRole: string
{
    case ADMIN = 'ADMIN';
    case TEACHER = 'TEACHER';
}
