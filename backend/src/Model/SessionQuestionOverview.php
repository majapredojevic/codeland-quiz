<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

final readonly class SessionQuestionOverview
{
    /**
     * @param SessionQuestionOptionOverview[] $options
     */
    public function __construct(
        public int $id,
        public int $sessionId,
        public ?int $sourceQuestionId,
        public string $questionText,
        public QuestionType $questionType,
        public ?string $imagePath,
        public int $timeLimitSeconds,
        public int $maxPoints,
        public int $questionOrder,
        public array $options,
    ) {
    }
}
