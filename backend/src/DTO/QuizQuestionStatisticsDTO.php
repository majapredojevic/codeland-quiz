<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\QuestionType;

final readonly class QuizQuestionStatisticsDTO
{
    public function __construct(
        public ?int $sourceQuestionId,
        public string $questionText,
        public QuestionType $questionType,
        public int $latestQuestionOrder,
        public bool $isCurrentlyDeleted,
        public int $sessionCount,
        public int $participantOpportunityCount,
        public int $answerCount,
        public int $correctAnswerCount,
        public int $incorrectAnswerCount,
        public int $unansweredCount,
        public ?float $accuracyPercentage,
        public ?float $answerRatePercentage,
        public ?int $averageResponseTimeMs,
        public ?int $averagePointsAwarded,
    ) {
    }
}
