<?php

declare(strict_types=1);

namespace CodeLandQuiz\Quiz;

use CodeLandQuiz\DTO\QuizStatisticsDTO;
use CodeLandQuiz\Quiz\Exception\QuizNotFoundException;
use CodeLandQuiz\Repository\QuizRepository;

final readonly class QuizStatisticsService
{
    public function __construct(
        private QuizRepository $quizzes,
        private QuizStatisticsAssembler $statisticsAssembler,
    ) {
    }

    public function getStatistics(int $quizId): QuizStatisticsDTO
    {
        $quiz = $this->quizzes->findOverviewById($quizId);

        if ($quiz === null) {
            throw new QuizNotFoundException('Quiz was not found.');
        }

        return $this->statisticsAssembler->assemble(
            quizId: $quiz->id,
            quizTitle: $quiz->title,
            quizVersion: $quiz->version,
        );
    }
}
