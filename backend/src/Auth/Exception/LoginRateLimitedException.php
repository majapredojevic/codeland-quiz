<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth\Exception;

use RuntimeException;

final class LoginRateLimitedException extends RuntimeException
{
    public function __construct(
        string $message = 'Too many failed login attempts.',
        private readonly int $retryAfterSeconds = 1,
    ) {
        parent::__construct($message);
    }

    public function getRetryAfterSeconds(): int
    {
        return max(1, $this->retryAfterSeconds);
    }
}
