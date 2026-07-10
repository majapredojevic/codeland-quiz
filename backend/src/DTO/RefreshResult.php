<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class RefreshResult
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public string $csrfToken,
        public int $expiresInSeconds,
    ) {
    }
}