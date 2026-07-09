<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class LoginDTO
{
    public function __construct(
        public string $email,
        public string $password,
    ) {
    }
}
