<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

final readonly class QuestionOptionOverview
{
    public function __construct(
        public int $id,
        public string $optionText,
        public bool $isCorrect,
        public int $optionOrder,
    ) {
    }
}
