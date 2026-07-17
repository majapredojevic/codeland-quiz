<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

use DateTimeImmutable;

final readonly class TopicOverview
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public int $quizCount,
        public int $createdById,
        public string $createdByName,
        public int $updatedById,
        public string $updatedByName,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
