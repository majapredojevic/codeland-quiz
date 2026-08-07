<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class StartNextSessionQuestionResultDTO
{
    public function __construct(
        public QuizSessionItemDTO $session,
        public PublicSessionQuestionDTO $currentQuestion,
        public int $questionCount,
        public int $previousQuestionOrder,
    ) {
    }
}
