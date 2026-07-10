<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class RefreshTokenResult
{
    public function __construct(
        public int $userId,
        public string $refreshToken,
    ) {
    }
}