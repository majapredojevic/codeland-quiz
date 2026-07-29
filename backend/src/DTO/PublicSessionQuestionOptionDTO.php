<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class PublicSessionQuestionOptionDTO
{
    public function __construct(
        public int $id,
        public string $optionText,
        public int $optionOrder,
    ) {
    }
}
