<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\QuizQuestionSessionStatisticsOverview;
use CodeLandQuiz\Model\QuizStatisticsSummaryOverview;

interface QuizStatisticsRepository
{
    public function findSummary(
        int $quizId,
    ): QuizStatisticsSummaryOverview;

    /**
     * @return QuizQuestionSessionStatisticsOverview[]
     */
    public function findQuestionSessionStatistics(
        int $quizId,
    ): array;
}
