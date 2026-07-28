<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\QuestionOverview;

interface QuestionRepository
{
    /**
     * @return QuestionOverview[]
     */
    public function findAllByQuizId(int $quizId): array;

    public function findOverviewByQuizAndId(
        int $quizId,
        int $questionId,
    ): ?QuestionOverview;
}
