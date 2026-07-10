<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use CodeLandQuiz\DTO\AccessTokenPayload;
use CodeLandQuiz\Model\UserRole;
use RuntimeException;

final readonly class AuthorizationService
{
    public function isGranted(
        AccessTokenPayload $user,
        UserRole ...$allowedRoles,
    ): bool {
        return in_array(
            $user->role,
            $allowedRoles,
            true,
        );
    }

    public function ensureGranted(
        AccessTokenPayload $user,
        UserRole ...$allowedRoles,
    ): void {
        if (!$this->isGranted($user, ...$allowedRoles)) {
            throw new RuntimeException('Access denied.');
        }
    }
}