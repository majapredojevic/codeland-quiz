<?php

declare(strict_types=1);

namespace CodeLandQuiz\Student;

use CodeLandQuiz\Auth\AuditLogService;
use CodeLandQuiz\DTO\CreateStudentDTO;
use CodeLandQuiz\DTO\StudentItemDTO;
use CodeLandQuiz\DTO\StudentListQueryDTO;
use CodeLandQuiz\DTO\StudentPageDTO;
use CodeLandQuiz\Model\AuditAction;
use CodeLandQuiz\Model\StudentOverview;
use CodeLandQuiz\Repository\StudentRepository;
use CodeLandQuiz\Student\Exception\StudentNotFoundException;
use CodeLandQuiz\Support\TransactionManager;
use RuntimeException;

final readonly class StudentService
{
    private const AUDIT_ENTITY_TYPE = 'STUDENT';

    public function __construct(
        private StudentRepository $students,
        private AuditLogService $auditLogService,
        private TransactionManager $transactionManager,
    ) {
    }

    public function listStudents(
        StudentListQueryDTO $query,
    ): StudentPageDTO {
        $totalItems = $this->students->count($query);
        $students = $this->students->findPage($query);
        $totalPages = $totalItems === 0
            ? 0
            : (int) ceil($totalItems / $query->pageSize);

        return new StudentPageDTO(
            items: array_map(
                fn (StudentOverview $student): StudentItemDTO =>
                    $this->toItem($student),
                $students,
            ),
            pageIndex: $query->pageIndex,
            pageSize: $query->pageSize,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }

    public function getStudent(int $studentId): StudentItemDTO
    {
        $student = $this->students->findOverviewById($studentId);

        if ($student === null) {
            throw new StudentNotFoundException('Student was not found.');
        }

        return $this->toItem($student);
    }

    public function createStudent(
        int $actorUserId,
        CreateStudentDTO $dto,
    ): StudentItemDTO {
        $studentId = $this->transactionManager->transactional(
            function () use ($actorUserId, $dto): int {
                $studentId = $this->students->create(
                    firstName: $dto->firstName,
                    lastName: $dto->lastName,
                    username: $dto->username,
                );

                $this->auditLogService->log(
                    action: AuditAction::STUDENT_CREATED,
                    userId: $actorUserId,
                    entityType: self::AUDIT_ENTITY_TYPE,
                    entityId: $studentId,
                    metadata: [
                        'isActive' => true,
                    ],
                );

                return $studentId;
            },
        );

        $student = $this->students->findOverviewById($studentId);

        if ($student === null) {
            throw new RuntimeException('Created student was not found.');
        }

        return $this->toItem($student);
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
