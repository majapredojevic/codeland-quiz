<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use DateTimeImmutable;

final readonly class QuizItemDTO
{
    public function __construct(
        public int $id,
        public string $title,
        public int $version,
        public ?string $description,
        public bool $isActive,
        public int $questionCount,
        public ?int $topicId,
        public ?string $topicName,
        public int $createdById,
        public string $createdByName,
        public int $updatedById,
        public string $updatedByName,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
