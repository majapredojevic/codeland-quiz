<?php

declare(strict_types=1);

namespace CodeLandQuiz\Admin\Http;

use CodeLandQuiz\DTO\UpdateTeacherDTO;
use CodeLandQuiz\Http\JsonRequest;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class UpdateTeacherRequest
{
    public static function from(Request $request): UpdateTeacherDTO
    {
        $body = JsonRequest::from($request);
        $hasName = $body->has('name');
        $hasEmail = $body->has('email');

        if (!$hasName && !$hasEmail) {
            throw new InvalidArgumentException('At least one teacher field must be provided.');
        }

        $name = null;
        $email = null;

        if ($hasName) {
            $name = $body->getValue('name');

            if (!is_string($name)) {
                throw new InvalidArgumentException('Teacher name must be a string.');
            }
        }

        if ($hasEmail) {
            $email = $body->getValue('email');

            if (!is_string($email)) {
                throw new InvalidArgumentException('Teacher email must be a string.');
            }
        }

        return new UpdateTeacherDTO(
            name: $name,
            email: $email,
        );
    }
}
