<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

final class BcryptPasswordHasher implements PasswordHasher
{
    private const ALGORITHM = PASSWORD_BCRYPT;

    public function hash(string $plainPassword): string
    {
        return password_hash($plainPassword, self::ALGORITHM);
    }

    public function verify(string $plainPassword, string $passwordHash): bool
    {
        return password_verify($plainPassword, $passwordHash);
    }

    public function needsRehash(string $passwordHash): bool
    {
        return password_needs_rehash($passwordHash, self::ALGORITHM);
    }
}