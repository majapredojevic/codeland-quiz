<?php

declare(strict_types=1);

namespace CodeLandQuiz\Admin\Http;

use CodeLandQuiz\DTO\CreateTeacherDTO;
use CodeLandQuiz\Http\JsonRequest;
use OpenSwoole\Http\Request;

final class CreateTeacherRequest
{
    public static function from(Request $request): CreateTeacherDTO
    {
        $body = JsonRequest::from($request);

        return new CreateTeacherDTO(
            name: $body->getString('name'),
            email: $body->getString('email'),
        );
    }
}
