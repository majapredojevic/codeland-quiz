<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

final readonly class SessionQuestionOptionOverview
{
    public function __construct(
        public int $id,
        public int $sessionQuestionId,
        public ?int $sourceOptionId,
        public string $optionText,
        public bool $isCorrect,
        public int $optionOrder,
    ) {
    }
}
