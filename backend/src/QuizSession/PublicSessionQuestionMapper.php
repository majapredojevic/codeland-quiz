<?php

declare(strict_types=1);

namespace CodeLandQuiz\QuizSession;

use CodeLandQuiz\DTO\PublicSessionQuestionDTO;
use CodeLandQuiz\DTO\PublicSessionQuestionOptionDTO;
use CodeLandQuiz\Model\SessionQuestionOptionOverview;
use CodeLandQuiz\Model\SessionQuestionOverview;

final class PublicSessionQuestionMapper
{
    public function map(
        SessionQuestionOverview $question,
    ): PublicSessionQuestionDTO {
        return new PublicSessionQuestionDTO(
            id: $question->id,
            questionText: $question->questionText,
            questionType: $question->questionType,
            imagePath: $question->imagePath,
            timeLimitSeconds: $question->timeLimitSeconds,
            maxPoints: $question->maxPoints,
            questionOrder: $question->questionOrder,
            options: array_map(
                static fn(
                    SessionQuestionOptionOverview $option,
                ): PublicSessionQuestionOptionDTO =>
                    new PublicSessionQuestionOptionDTO(
                        id: $option->id,
                        optionText: $option->optionText,
                        optionOrder: $option->optionOrder,
                    ),
                $question->options,
            ),
        );
    }
}
