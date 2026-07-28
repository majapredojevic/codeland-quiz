<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class CreateQuizDTO
{
    public function __construct(
        public string $title,
        public int $version,
        public ?string $description,
        public ?int $topicId,
    ) {
    }
}
