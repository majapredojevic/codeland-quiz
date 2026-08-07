<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

use DateTimeImmutable;

final readonly class QuizQuestionSessionStatisticsOverview
{
    public function __construct(
        public int $sessionQuestionId,
        public int $sessionId,
        public ?int $sourceQuestionId,
        public string $questionText,
        public QuestionType $questionType,
        public int $questionOrder,
        public DateTimeImmutable $sessionEndedAt,
        public bool $sourceQuestionCurrentlyDeleted,
        public int $participantOpportunityCount,
        public int $answerCount,
        public int $correctAnswerCount,
        public int $totalResponseTimeMs,
        public int $totalPointsAwarded,
    ) {
    }
}
