<?php

declare(strict_types=1);

namespace CodeLandQuiz\Student\Http;

use CodeLandQuiz\DTO\CreateStudentDTO;
use CodeLandQuiz\Http\JsonRequest;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class CreateStudentRequest
{
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
            firstName: StudentFieldValidator::firstName(
                $body->getValue('firstName'),
            ),
            lastName: StudentFieldValidator::lastName(
                $body->getValue('lastName'),
            ),
            username: StudentFieldValidator::username(
                $body->getValue('username'),
            ),
        );
    }
}
