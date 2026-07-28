<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

use DateTimeImmutable;

final readonly class QuestionOverview
{
    /**
     * @param QuestionOptionOverview[] $options
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
