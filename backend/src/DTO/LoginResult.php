<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\UserRole;

final readonly class LoginResult
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public string $csrfToken,
        public int $expiresInSeconds,
        public int $userId,
        public string $userName,
        public string $userEmail,
        public UserRole $userRole,
    ) {
    }
}
