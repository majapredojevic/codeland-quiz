<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\QuizSessionStatus;
use DateTimeImmutable;

final readonly class GameSessionPreviewDTO
{
    public function __construct(
        public string $quizTitle,
        public int $quizVersion,
        public QuizSessionStatus $status,
        public int $participantCount,
        public bool $canJoin,
        public ?DateTimeImmutable $joinDeadline,
    ) {
    }
}
