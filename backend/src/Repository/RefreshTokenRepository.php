<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\RefreshToken;

interface RefreshTokenRepository
{
    public function save(RefreshToken $refreshToken): int;

    public function findValidByPlainToken(string $plainToken): ?RefreshToken;

    public function revoke(int $refreshTokenId, ?int $replacedByTokenId = null): void;

    public function revokeAllForUser(int $userId): int;
}
