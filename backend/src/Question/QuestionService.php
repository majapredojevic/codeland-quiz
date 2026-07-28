<?php

declare(strict_types=1);

namespace CodeLandQuiz\Question;

use CodeLandQuiz\DTO\QuestionItemDTO;
use CodeLandQuiz\DTO\QuestionOptionItemDTO;
use CodeLandQuiz\Model\QuestionOptionOverview;
use CodeLandQuiz\Model\QuestionOverview;
use CodeLandQuiz\Question\Exception\QuestionNotFoundException;
use CodeLandQuiz\Quiz\Exception\QuizNotFoundException;
use CodeLandQuiz\Repository\QuestionRepository;
use CodeLandQuiz\Repository\QuizRepository;

final readonly class QuestionService
{
    public function __construct(
        private QuestionRepository $questions,
        private QuizRepository $quizzes,
    ) {
    }

    /**
     * @return QuestionItemDTO[]
     */
    public function listQuestions(int $quizId): array
    {
        $this->ensureQuizExists($quizId);

        return array_map(
            fn (QuestionOverview $question): QuestionItemDTO =>
                $this->toQuestionItem($question),
            $this->questions->findAllByQuizId($quizId),
        );
    }

    public function getQuestion(
        int $quizId,
        int $questionId,
    ): QuestionItemDTO {
        $this->ensureQuizExists($quizId);

        $question = $this->questions->findOverviewByQuizAndId(
            $quizId,
            $questionId,
        );

        if ($question === null) {
            throw new QuestionNotFoundException('Question was not found.');
        }

        return $this->toQuestionItem($question);
    }

    private function ensureQuizExists(int $quizId): void
    {
        if ($this->quizzes->findOverviewById($quizId) === null) {
            throw new QuizNotFoundException('Quiz was not found.');
        }
    }

    private function toQuestionItem(
        QuestionOverview $question,
    ): QuestionItemDTO {
        return new QuestionItemDTO(
            id: $question->id,
            quizId: $question->quizId,
            questionText: $question->questionText,
            questionType: $question->questionType,
            imagePath: $question->imagePath,
            timeLimitSeconds: $question->timeLimitSeconds,
            maxPoints: $question->maxPoints,
            questionOrder: $question->questionOrder,
            options: array_map(
                fn (QuestionOptionOverview $option): QuestionOptionItemDTO =>
                    $this->toOptionItem($option),
                $question->options,
            ),
            createdAt: $question->createdAt,
            updatedAt: $question->updatedAt,
        );
    }

    private function toOptionItem(
        QuestionOptionOverview $option,
    ): QuestionOptionItemDTO {
        return new QuestionOptionItemDTO(
            id: $option->id,
            optionText: $option->optionText,
            isCorrect: $option->isCorrect,
            optionOrder: $option->optionOrder,
        );
    }
}
