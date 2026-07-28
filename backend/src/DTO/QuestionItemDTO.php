<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\QuestionType;
use DateTimeImmutable;

final readonly class QuestionItemDTO
{
    /**
     * @param QuestionOptionItemDTO[] $options
     */
    public function __construct(
        public int $id,
        public int $quizId,
        public string $questionText,
        public QuestionType $questionType,
        public ?string $imagePath,
        public int $timeLimitSeconds,
        public int $maxPoints,
        public int $questionOrder,
        public array $options,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
