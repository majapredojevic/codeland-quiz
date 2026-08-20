<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use InvalidArgumentException;

final class PasswordPolicy
{
    public static function validate(
        string $password,
        string $subject = 'Password',
    ): void
    {
        if (strlen($password) < 8) {
            throw new InvalidArgumentException(
                sprintf('%s must be at least 8 characters long.', $subject),
            );
        }

        if (preg_match('/[A-Z]/', $password) !== 1) {
            throw new InvalidArgumentException(
                sprintf('%s must contain at least one uppercase letter.', $subject),
            );
        }

        if (preg_match('/[a-z]/', $password) !== 1) {
            throw new InvalidArgumentException(
                sprintf('%s must contain at least one lowercase letter.', $subject),
            );
        }

        if (preg_match('/[0-9]/', $password) !== 1) {
            throw new InvalidArgumentException(
                sprintf('%s must contain at least one number.', $subject),
            );
        }

        if (preg_match('/[^A-Za-z0-9\s]/', $password) !== 1) {
            throw new InvalidArgumentException(
                sprintf('%s must contain at least one special character.', $subject),
            );
        }
    }
}
