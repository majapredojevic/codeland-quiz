<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth\Http;

use CodeLandQuiz\DTO\ChangePasswordDTO;
use CodeLandQuiz\Http\JsonRequest;
use OpenSwoole\Http\Request;

final class ChangePasswordRequest
{
    public static function from(
        Request $request,
    ): ChangePasswordDTO {
        $body = JsonRequest::from($request);

        return new ChangePasswordDTO(
            currentPassword: $body->getString('currentPassword'),
            newPassword: $body->getString('newPassword'),
            newPasswordConfirmation: $body->getString('newPasswordConfirmation'),
        );
    }
}
