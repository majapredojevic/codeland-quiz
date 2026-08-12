<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class QuizSessionPresentationStateDTO
{
    public function __construct(
        public QuizSessionItemDTO $session,
        public ?PublicSessionQuestionDTO $currentQuestion,
        public ?ClosedSessionQuestionStateDTO $questionResult,
        public ?FinalQuizSessionResultDTO $finalResult,
    ) {
    }
}
