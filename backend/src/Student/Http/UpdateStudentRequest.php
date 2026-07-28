<?php

declare(strict_types=1);

namespace CodeLandQuiz\Student\Http;

use CodeLandQuiz\DTO\UpdateStudentDTO;
use CodeLandQuiz\Http\JsonRequest;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class UpdateStudentRequest
{
    private const SUPPORTED_FIELDS = [
        'firstName',
        'lastName',
        'username',
    ];

    public static function from(Request $request): UpdateStudentDTO
    {
        $body = JsonRequest::from($request);
        $data = $body->all();
        $hasFirstName = array_key_exists('firstName', $data);
        $hasLastName = array_key_exists('lastName', $data);
        $hasUsername = array_key_exists('username', $data);

        if (!$hasFirstName && !$hasLastName && !$hasUsername) {
            throw new InvalidArgumentException(
                'At least one student field must be provided.',
            );
        }

        if (array_diff(array_keys($data), self::SUPPORTED_FIELDS) !== []) {
            throw new InvalidArgumentException(
                'Unsupported student field was provided.',
            );
        }

        return new UpdateStudentDTO(
            hasFirstName: $hasFirstName,
            firstName: $hasFirstName
                ? StudentFieldValidator::firstName($data['firstName'])
                : null,
            hasLastName: $hasLastName,
            lastName: $hasLastName
                ? StudentFieldValidator::lastName($data['lastName'])
                : null,
            hasUsername: $hasUsername,
            username: $hasUsername
                ? StudentFieldValidator::username($data['username'])
                : null,
        );
    }
}
