<?php

declare(strict_types=1);

namespace CodeLandQuiz\Config;

enum CookieSameSite: string
{
    case STRICT = 'Strict';
    case LAX = 'Lax';
    case NONE = 'None';
}
