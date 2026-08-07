<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\QuestionType;

final readonly class SessionReportQuestionDTO
{
    /**
     * @param SessionReportQuestionOptionDTO[] $options
     */
    public function __construct(
        public int $id,
        public string $questionText,
        public QuestionType $questionType,
        public ?string $imagePath,
        public int $timeLimitSeconds,
        public int $maxPoints,
        public int $questionOrder,
        public array $options,
        public SessionReportQuestionStatsDTO $stats,
    ) {
    }
}
