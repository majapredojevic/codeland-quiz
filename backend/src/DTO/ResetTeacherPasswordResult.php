<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class ResetTeacherPasswordResult
{
    public function __construct(
        public UserListItemDTO $user,
        public string $temporaryPassword,
    ) {
    }
}
