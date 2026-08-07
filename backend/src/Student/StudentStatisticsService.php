<?php

declare(strict_types=1);

namespace CodeLandQuiz\Student;

use CodeLandQuiz\DTO\StudentItemDTO;
use CodeLandQuiz\DTO\StudentSessionPerformancePageDTO;
use CodeLandQuiz\DTO\StudentStatisticsDTO;
use CodeLandQuiz\DTO\StudentStatisticsSessionQueryDTO;
use CodeLandQuiz\Model\StudentOverview;
use CodeLandQuiz\Repository\StudentRepository;
use CodeLandQuiz\Repository\StudentStatisticsRepository;
use CodeLandQuiz\Student\Exception\StudentNotFoundException;

final readonly class StudentStatisticsService
{
    public function __construct(
        private StudentRepository $students,
        private StudentStatisticsRepository $statistics,
        private StudentStatisticsAssembler $assembler,
    ) {
    }

    public function getStatistics(
        int $studentId,
    ): StudentStatisticsDTO {
        $student = $this->getStudent($studentId);

        return $this->assembler->assemble($this->toItem($student));
    }

    public function listSessionPerformances(
        int $studentId,
        StudentStatisticsSessionQueryDTO $query,
    ): StudentSessionPerformancePageDTO {
        $this->getStudent($studentId);

        $performances = $this->statistics->findPerformancePage(
            $studentId,
            $query,
        );
        $totalItems = $this->statistics->countPerformances(
            $studentId,
            $query,
        );
        $totalPages = $totalItems === 0
            ? 0
            : (int) ceil($totalItems / $query->pageSize);

        return new StudentSessionPerformancePageDTO(
            items: array_map(
                $this->assembler->toPerformanceDTO(...),
                $performances,
            ),
            pageIndex: $query->pageIndex,
            pageSize: $query->pageSize,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }

    private function getStudent(int $studentId): StudentOverview
    {
        $student = $this->students->findOverviewById($studentId);

        if ($student === null) {
            throw new StudentNotFoundException('Student was not found.');
        }

        return $student;
    }

    private function toItem(StudentOverview $student): StudentItemDTO
    {
        return new StudentItemDTO(
            id: $student->id,
            firstName: $student->firstName,
            lastName: $student->lastName,
            username: $student->username,
            isActive: $student->isActive,
            createdAt: $student->createdAt,
            updatedAt: $student->updatedAt,
        );
    }
}
