<?php

declare(strict_types=1);

namespace CodeLandQuiz\Student\Http;

use CodeLandQuiz\DTO\CreateStudentDTO;
use CodeLandQuiz\Http\JsonRequest;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class CreateStudentRequest
{
    private const MAX_NAME_LENGTH = 100;
    private const MIN_USERNAME_LENGTH = 3;
    private const MAX_USERNAME_LENGTH = 80;
    private const USERNAME_PATTERN =
        '/^[a-z0-9](?:[a-z0-9._-]{1,78}[a-z0-9])$/';

    public static function from(Request $request): CreateStudentDTO
    {
        $body = JsonRequest::from($request);

        if (!$body->has('firstName')) {
            throw new InvalidArgumentException('Student first name is required.');
        }

        if (!$body->has('lastName')) {
            throw new InvalidArgumentException('Student last name is required.');
        }

        if (!$body->has('username')) {
            throw new InvalidArgumentException('Student username is required.');
        }

        return new CreateStudentDTO(
            firstName: self::nameValue(
                $body->getValue('firstName'),
                'Student first name must be a string.',
                'Student first name cannot be empty.',
                'Student first name cannot exceed 100 characters.',
            ),
            lastName: self::nameValue(
                $body->getValue('lastName'),
                'Student last name must be a string.',
                'Student last name cannot be empty.',
                'Student last name cannot exceed 100 characters.',
            ),
            username: self::usernameValue($body->getValue('username')),
        );
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

    private static function usernameValue(mixed $value): string
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
}
