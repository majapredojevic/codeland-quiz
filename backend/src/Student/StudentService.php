<?php

declare(strict_types=1);

namespace CodeLandQuiz\Student;

use CodeLandQuiz\Auth\AuditLogService;
use CodeLandQuiz\DTO\CreateStudentDTO;
use CodeLandQuiz\DTO\StudentItemDTO;
use CodeLandQuiz\DTO\StudentListQueryDTO;
use CodeLandQuiz\DTO\StudentPageDTO;
use CodeLandQuiz\DTO\UpdateStudentDTO;
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

    public function updateStudent(
        int $actorUserId,
        int $studentId,
        UpdateStudentDTO $dto,
    ): StudentItemDTO {
        $this->transactionManager->transactional(
            function () use ($actorUserId, $dto, $studentId): void {
                $student = $this->students->findOverviewByIdForUpdate(
                    $studentId,
                );

                if ($student === null) {
                    throw new StudentNotFoundException('Student was not found.');
                }

                $firstName = $dto->hasFirstName
                    ? (string) $dto->firstName
                    : $student->firstName;
                $lastName = $dto->hasLastName
                    ? (string) $dto->lastName
                    : $student->lastName;
                $username = $dto->hasUsername
                    ? (string) $dto->username
                    : $student->username;
                $changedFields = $this->changedFields(
                    $student,
                    $firstName,
                    $lastName,
                    $username,
                );

                if ($changedFields === []) {
                    return;
                }

                $this->students->update(
                    studentId: $student->id,
                    firstName: $firstName,
                    lastName: $lastName,
                    username: $username,
                );

                $this->auditLogService->log(
                    action: AuditAction::STUDENT_UPDATED,
                    userId: $actorUserId,
                    entityType: self::AUDIT_ENTITY_TYPE,
                    entityId: $student->id,
                    metadata: [
                        'changedFields' => $changedFields,
                    ],
                );
            },
        );

        return $this->getLatestStudent($studentId, 'Updated student was not found.');
    }

    public function activateStudent(
        int $actorUserId,
        int $studentId,
    ): StudentItemDTO {
        $this->changeActiveStatus(
            actorUserId: $actorUserId,
            studentId: $studentId,
            isActive: true,
            auditAction: AuditAction::STUDENT_ACTIVATED,
        );

        return $this->getLatestStudent(
            $studentId,
            'Activated student was not found.',
        );
    }

    public function deactivateStudent(
        int $actorUserId,
        int $studentId,
    ): StudentItemDTO {
        $this->changeActiveStatus(
            actorUserId: $actorUserId,
            studentId: $studentId,
            isActive: false,
            auditAction: AuditAction::STUDENT_DEACTIVATED,
        );

        return $this->getLatestStudent(
            $studentId,
            'Deactivated student was not found.',
        );
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

    private function changeActiveStatus(
        int $actorUserId,
        int $studentId,
        bool $isActive,
        AuditAction $auditAction,
    ): void {
        $this->transactionManager->transactional(
            function () use ($actorUserId, $auditAction, $isActive, $studentId): void {
                $student = $this->students->findOverviewByIdForUpdate(
                    $studentId,
                );

                if ($student === null) {
                    throw new StudentNotFoundException('Student was not found.');
                }

                if ($student->isActive === $isActive) {
                    return;
                }

                $this->students->updateActiveStatus($student->id, $isActive);

                $this->auditLogService->log(
                    action: $auditAction,
                    userId: $actorUserId,
                    entityType: self::AUDIT_ENTITY_TYPE,
                    entityId: $student->id,
                    metadata: [
                        'isActive' => $isActive,
                    ],
                );
            },
        );
    }

    /**
     * @return string[]
     */
    private function changedFields(
        StudentOverview $student,
        string $firstName,
        string $lastName,
        string $username,
    ): array {
        $changedFields = [];

        if ($firstName !== $student->firstName) {
            $changedFields[] = 'firstName';
        }

        if ($lastName !== $student->lastName) {
            $changedFields[] = 'lastName';
        }

        if ($username !== $student->username) {
            $changedFields[] = 'username';
        }

        return $changedFields;
    }

    private function getLatestStudent(
        int $studentId,
        string $missingMessage,
    ): StudentItemDTO {
        $student = $this->students->findOverviewById($studentId);

        if ($student === null) {
            throw new RuntimeException($missingMessage);
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
