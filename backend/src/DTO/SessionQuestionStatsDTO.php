<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class SessionQuestionStatsDTO
{
    public function __construct(
        public int $participantCount,
        public int $answerCount,
        public int $correctAnswerCount,
        public int $incorrectAnswerCount,
        public int $unansweredCount,
    ) {
    }
}
