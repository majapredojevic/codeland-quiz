<?php

declare(strict_types=1);

namespace CodeLandQuiz\Student\Http;

use InvalidArgumentException;

final class StudentFieldValidator
{
    private const MAX_NAME_LENGTH = 100;
    private const MIN_USERNAME_LENGTH = 3;
    private const MAX_USERNAME_LENGTH = 80;
    private const USERNAME_PATTERN =
        '/^[a-z0-9](?:[a-z0-9._-]{1,78}[a-z0-9])$/';

    public static function firstName(mixed $value): string
    {
        return self::nameValue(
            $value,
            'Student first name must be a string.',
            'Student first name cannot be empty.',
            'Student first name cannot exceed 100 characters.',
        );
    }

    public static function lastName(mixed $value): string
    {
        return self::nameValue(
            $value,
            'Student last name must be a string.',
            'Student last name cannot be empty.',
            'Student last name cannot exceed 100 characters.',
        );
    }

    public static function username(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Student username must be a string.');
        }

        $username = strtolower(trim($value));
        $usernameLength = strlen($username);

        if (
            $usernameLength < self::MIN_USERNAME_LENGTH
            || $usernameLength > self::MAX_USERNAME_LENGTH
        ) {
            throw new InvalidArgumentException(
                'Student username must contain between 3 and 80 characters.',
            );
        }

        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            throw new InvalidArgumentException(
                'Student username may contain only lowercase letters, numbers, dots, underscores and hyphens, '
                . 'and must begin and end with a letter or number.',
            );
        }

        return $username;
    }

    private static function nameValue(
        mixed $value,
        string $invalidTypeMessage,
        string $emptyMessage,
        string $tooLongMessage,
    ): string {
        if (!is_string($value)) {
            throw new InvalidArgumentException($invalidTypeMessage);
        }

        $name = trim($value);

        if ($name === '') {
            throw new InvalidArgumentException($emptyMessage);
        }

        if (mb_strlen($name, 'UTF-8') > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException($tooLongMessage);
        }

        return $name;
    }
}
