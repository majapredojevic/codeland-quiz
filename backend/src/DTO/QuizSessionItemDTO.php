<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\QuizSessionStatus;
use DateTimeImmutable;

final readonly class QuizSessionItemDTO
{
    public function __construct(
        public int $id,
        public int $quizId,
        public int $hostUserId,
        public string $hostUserName,
        public string $quizTitle,
        public int $quizVersion,
        public string $gamePin,
        public QuizSessionStatus $status,
        public ?int $currentQuestionOrder,
        public ?DateTimeImmutable $currentQuestionStartedAt,
        public ?DateTimeImmutable $currentQuestionDeadline,
        public ?DateTimeImmutable $joinDeadline,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endedAt,
        public DateTimeImmutable $createdAt,
        public int $questionCount,
        public int $participantCount,
    ) {
    }
}
