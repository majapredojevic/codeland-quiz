<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use RuntimeException;

final class DefaultCsrfTokenService implements CsrfTokenService
{
    private const TOKEN_BYTES = 32;

    public function generate(): string
    {
        try {
            return bin2hex(random_bytes(self::TOKEN_BYTES));
        } catch (\Throwable $exception) {
            throw new RuntimeException('CSRF token could not be generated.', 0, $exception);
        }
    }
}