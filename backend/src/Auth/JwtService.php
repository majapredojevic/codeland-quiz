<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use CodeLandQuiz\DTO\AccessTokenPayload;
use CodeLandQuiz\Model\User;

interface JwtService
{
    public function createAccessToken(User $user): string;

    public function decodeAccessToken(string $jwt): AccessTokenPayload;

    public function isAccessTokenValid(string $jwt): bool;
}
