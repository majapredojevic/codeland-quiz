<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class ChangePasswordDTO
{
    public function __construct(
        public int $authenticatedUserId,
        public string $currentPassword,
        public string $newPassword,
        public string $newPasswordConfirmation,
    ) {
    }
}
