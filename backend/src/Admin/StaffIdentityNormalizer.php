<?php

declare(strict_types=1);

namespace CodeLandQuiz\Admin;

use InvalidArgumentException;

final class StaffIdentityNormalizer
{
    public static function name(string $name, string $identity = 'Staff'): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException(sprintf(
                '%s name is required.',
                $identity,
            ));
        }

        return $name;
    }

    public static function email(string $email, string $identity = 'Staff'): string
    {
        $email = strtolower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(sprintf(
                '%s email is invalid.',
                $identity,
            ));
        }

        return $email;
    }
}
