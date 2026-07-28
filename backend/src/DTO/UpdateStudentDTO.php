<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class UpdateStudentDTO
{
    public function __construct(
        public bool $hasFirstName,
        public ?string $firstName,
        public bool $hasLastName,
        public ?string $lastName,
        public bool $hasUsername,
        public ?string $username,
    ) {
    }
}
