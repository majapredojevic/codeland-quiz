<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class UpdateQuizDTO
{
    public function __construct(
        public bool $hasTitle,
        public ?string $title,
        public bool $hasVersion,
        public ?int $version,
        public bool $hasDescription,
        public ?string $description,
        public bool $hasTopicId,
        public ?int $topicId,
    ) {
    }
}
