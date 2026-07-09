<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

interface PasswordHasher
{
    public function hash(string $plainPassword): string;

    public function verify(string $plainPassword, string $passwordHash): bool;

    public function needsRehash(string $passwordHash): bool;
}
