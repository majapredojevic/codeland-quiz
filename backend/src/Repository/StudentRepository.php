<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\DTO\StudentListQueryDTO;
use CodeLandQuiz\Model\StudentOverview;

interface StudentRepository
{
    /**
     * @return StudentOverview[]
     */
    public function findPage(StudentListQueryDTO $query): array;

    public function count(StudentListQueryDTO $query): int;

    public function findOverviewById(int $studentId): ?StudentOverview;

    public function findOverviewByIdForUpdate(
        int $studentId,
    ): ?StudentOverview;

    public function create(
        string $firstName,
        string $lastName,
        string $username,
    ): int;

    public function update(
        int $studentId,
        string $firstName,
        string $lastName,
        string $username,
    ): void;

    public function updateActiveStatus(
        int $studentId,
        bool $isActive,
    ): void;
}
