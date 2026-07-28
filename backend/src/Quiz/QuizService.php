<?php

declare(strict_types=1);

namespace CodeLandQuiz\Quiz;

use CodeLandQuiz\DTO\ListQuizzesDTO;
use CodeLandQuiz\DTO\QuizItemDTO;
use CodeLandQuiz\DTO\QuizListResultDTO;
use CodeLandQuiz\Model\QuizOverview;
use CodeLandQuiz\Repository\QuizRepository;
use CodeLandQuiz\Quiz\Exception\QuizNotFoundException;

final readonly class QuizService
{
    public function __construct(
        private QuizRepository $quizzes,
    ) {
    }

    public function listQuizzes(
        ListQuizzesDTO $dto,
    ): QuizListResultDTO {
        $totalItems = $this->quizzes->count(
            $dto->search,
            $dto->topicId,
            $dto->status,
        );
        $quizzes = $this->quizzes->findPage(
            $dto->pageSize,
            $dto->getOffset(),
            $dto->search,
            $dto->topicId,
            $dto->status,
            $dto->sort,
        );
        $totalPages = $totalItems === 0
            ? 0
            : (int) ceil($totalItems / $dto->pageSize);

        return new QuizListResultDTO(
            quizzes: array_map(
                fn (QuizOverview $quiz): QuizItemDTO => $this->toQuizItem($quiz),
                $quizzes,
            ),
            pageIndex: $dto->pageIndex,
            pageSize: $dto->pageSize,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }

    public function getQuiz(int $id): QuizItemDTO
    {
        $quiz = $this->quizzes->findOverviewById($id);

        if ($quiz === null) {
            throw new QuizNotFoundException('Quiz was not found.');
        }

        return $this->toQuizItem($quiz);
    }

    private function toQuizItem(QuizOverview $quiz): QuizItemDTO
    {
        return new QuizItemDTO(
            id: $quiz->id,
            title: $quiz->title,
            version: $quiz->version,
            description: $quiz->description,
            isActive: $quiz->isActive,
            questionCount: $quiz->questionCount,
            topicId: $quiz->topicId,
            topicName: $quiz->topicName,
            createdById: $quiz->createdById,
            createdByName: $quiz->createdByName,
            updatedById: $quiz->updatedById,
            updatedByName: $quiz->updatedByName,
            createdAt: $quiz->createdAt,
            updatedAt: $quiz->updatedAt,
        );
    }
}
