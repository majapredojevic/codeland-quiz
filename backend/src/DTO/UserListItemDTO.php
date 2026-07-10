<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\UserRole;

final readonly class UserListItemDTO
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public UserRole $role,
        public bool $isActive,
        public bool $mustChangePassword,
    ) {
    }
}