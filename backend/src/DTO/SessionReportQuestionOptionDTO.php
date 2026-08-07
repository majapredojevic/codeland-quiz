<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class SessionReportQuestionOptionDTO
{
    public function __construct(
        public int $id,
        public string $optionText,
        public bool $isCorrect,
        public int $optionOrder,
    ) {
    }
}
