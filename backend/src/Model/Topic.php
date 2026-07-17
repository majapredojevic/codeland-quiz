<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

use DateTimeImmutable;

final readonly class Topic
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public int $createdById,
        public int $updatedById,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
