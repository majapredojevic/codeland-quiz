<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class CreateTeacherDTO
{
    public function __construct(
        public string $name,
        public string $email,
    ) {
    }
}