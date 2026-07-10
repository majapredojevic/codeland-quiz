<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\UserRole;

final readonly class AccessTokenPayload
{
    public function __construct(
        public int $userId,
        public string $email,
        public UserRole $role,
        public int $issuedAt,
        public int $expiresAt,
    ) {
    }
}