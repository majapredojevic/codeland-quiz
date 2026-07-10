<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\UserRole;

final readonly class CreateTeacherResult
{
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
        public UserRole $role,
        public string $temporaryPassword,
    ) {
    }
}