<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

use DateTimeImmutable;

final readonly class StudentOverview
{
    public function __construct(
        public int $id,
        public string $firstName,
        public string $lastName,
        public string $username,
        public bool $isActive,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
