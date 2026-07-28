<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\QuestionType;

final readonly class UpdateQuestionDTO
{
    /**
     * @param QuestionOptionInputDTO[]|null $options
     */
    public function __construct(
        public bool $hasQuestionText,
        public ?string $questionText,
        public bool $hasQuestionType,
        public ?QuestionType $questionType,
        public bool $hasImagePath,
        public ?string $imagePath,
        public bool $hasTimeLimitSeconds,
        public ?int $timeLimitSeconds,
        public bool $hasMaxPoints,
        public ?int $maxPoints,
        public bool $hasOptions,
        public ?array $options,
    ) {
    }
}
