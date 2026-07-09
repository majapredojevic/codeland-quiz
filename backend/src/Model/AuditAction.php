<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

enum AuditAction: string
{
    case LOGIN_SUCCESS = 'LOGIN_SUCCESS';
    case LOGIN_FAILED = 'LOGIN_FAILED';
    case LOGOUT = 'LOGOUT';
    case PASSWORD_CHANGED = 'PASSWORD_CHANGED';

    case QUIZ_CREATED = 'QUIZ_CREATED';
    case QUIZ_UPDATED = 'QUIZ_UPDATED';
    case QUIZ_DELETED = 'QUIZ_DELETED';

    case SESSION_STARTED = 'SESSION_STARTED';
    case SESSION_FINISHED = 'SESSION_FINISHED';
}