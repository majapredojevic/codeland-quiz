<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use CodeLandQuiz\DTO\RefreshTokenResult;
use CodeLandQuiz\Model\User;

interface RefreshTokenService
{
    public function create(User $user): string;

    public function rotate(string $refreshToken): RefreshTokenResult;

    public function revoke(string $refreshToken): void;
}