<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\DTO\StudentStatisticsSessionQueryDTO;
use CodeLandQuiz\Model\StudentSessionPerformanceOverview;

interface StudentStatisticsRepository
{
    /**
     * @return StudentSessionPerformanceOverview[]
     */
    public function findAllPerformances(
        int $studentId,
    ): array;

    /**
     * @return StudentSessionPerformanceOverview[]
     */
    public function findPerformancePage(
        int $studentId,
        StudentStatisticsSessionQueryDTO $query,
    ): array;

    public function countPerformances(
        int $studentId,
        StudentStatisticsSessionQueryDTO $query,
    ): int;
}
