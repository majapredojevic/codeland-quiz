<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

use DateTimeImmutable;

final readonly class Quiz
{
    public function __construct(
        public int $id,
        public ?int $topicId,
        public int $createdById,
        public int $updatedById,
        public string $title,
        public int $version,
        public ?string $description,
        public bool $isActive,
        public bool $isDeleted,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {
    }
}
