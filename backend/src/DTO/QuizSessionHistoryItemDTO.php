<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\QuizSessionStatus;
use DateTimeImmutable;

final readonly class QuizSessionHistoryItemDTO
{
    public function __construct(
        public int $id,
        public int $quizId,
        public string $quizTitle,
        public int $quizVersion,
        public int $hostUserId,
        public string $hostUserName,
        public string $gamePin,
        public QuizSessionStatus $status,
        public int $questionCount,
        public int $participantCount,
        public int $removedParticipantCount,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endedAt,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
