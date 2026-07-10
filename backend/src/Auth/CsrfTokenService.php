<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

interface CsrfTokenService
{
    public function generate(): string;

    public function validate(string $headerToken, string $cookieToken): bool;
}