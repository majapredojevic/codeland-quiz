<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class CreateStudentDTO
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $username,
    ) {
    }
}
