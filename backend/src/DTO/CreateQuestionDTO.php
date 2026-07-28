<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\QuestionType;

final readonly class CreateQuestionDTO
{
    /**
     * @param QuestionOptionInputDTO[] $options
     */
    public function __construct(
        public string $questionText,
        public QuestionType $questionType,
        public ?string $imagePath,
        public int $timeLimitSeconds,
        public int $maxPoints,
        public array $options,
    ) {
    }
}
