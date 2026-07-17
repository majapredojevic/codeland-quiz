<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class UpdateTopicDTO
{
    public function __construct(
        public bool $hasName,
        public ?string $name,
        public bool $hasDescription,
        public ?string $description,
    ) {
    }
}
