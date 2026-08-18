<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\QuizSessionStatus;
use DateTimeImmutable;

final readonly class ParticipantConnectionResultDTO
{
    /**
     * @param int[] $currentQuestionSelectedOptionIds
     */
    public function __construct(
        public SessionParticipantItemDTO $participant,
        public int $sessionId,
        public string $quizTitle,
        public int $quizVersion,
        public QuizSessionStatus $sessionStatus,
        public ?int $currentQuestionOrder,
        public ?PublicSessionQuestionDTO $currentQuestion,
        public ?ClosedSessionQuestionStateDTO $closedQuestion,
        public ?FinalQuizSessionResultDTO $finalResult,
        public array $currentQuestionSelectedOptionIds,
        public ?DateTimeImmutable $currentQuestionStartedAt,
        public ?DateTimeImmutable $currentQuestionDeadline,
        public int $questionCount,
        public DateTimeImmutable $participantTokenExpiresAt,
    ) {
    }
}
