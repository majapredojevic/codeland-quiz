<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use CodeLandQuiz\Model\User;

interface JwtService
{
    public function createAccessToken(User $user): string;
}