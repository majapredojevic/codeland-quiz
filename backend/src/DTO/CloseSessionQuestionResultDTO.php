<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class CloseSessionQuestionResultDTO
{
    public function __construct(
        public QuizSessionItemDTO $session,
        public ClosedSessionQuestionStateDTO $closedQuestion,
        public bool $stateChanged,
    ) {
    }
}
