<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class QuestionOptionInputDTO
{
    public function __construct(
        public string $optionText,
        public bool $isCorrect,
    ) {
    }
}
