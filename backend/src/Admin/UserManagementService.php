<?php

declare(strict_types=1);

namespace CodeLandQuiz\Admin;

use CodeLandQuiz\Admin\Exception\TeacherEmailAlreadyExistsException;
use CodeLandQuiz\Admin\Exception\TeacherNotFoundException;
use CodeLandQuiz\Auth\AuditLogService;
use CodeLandQuiz\Auth\PasswordHasher;
use CodeLandQuiz\Auth\TemporaryPasswordGenerator;
use CodeLandQuiz\DTO\CreateTeacherDTO;
use CodeLandQuiz\DTO\CreateTeacherResult;
use CodeLandQuiz\DTO\ListTeachersDTO;
use CodeLandQuiz\DTO\ResetTeacherPasswordResult;
use CodeLandQuiz\DTO\TeacherListResultDTO;
use CodeLandQuiz\DTO\UpdateTeacherDTO;
use CodeLandQuiz\DTO\UserListItemDTO;
use CodeLandQuiz\Model\AuditAction;
use CodeLandQuiz\Model\NewUser;
use CodeLandQuiz\Model\User;
use CodeLandQuiz\Model\UserRole;
use CodeLandQuiz\Repository\RefreshTokenRepository;
use CodeLandQuiz\Repository\UserRepository;
use CodeLandQuiz\Support\TransactionManager;
use InvalidArgumentException;

final readonly class UserManagementService
{
    private const AUDIT_ENTITY_TYPE = 'USER';

    public function __construct(
        private UserRepository $users,
        private RefreshTokenRepository $refreshTokens,
        private TemporaryPasswordGenerator $temporaryPasswordGenerator,
        private PasswordHasher $passwordHasher,
        private AuditLogService $auditLogService,
        private TransactionManager $transactionManager,
    ) {}

    public function getTeacher(int $id): UserListItemDTO
    {
        $teacher = $this->users->findTeacherById($id);

        if ($teacher === null) {
            throw new TeacherNotFoundException('Teacher was not found.');
        }

        return $this->toUserListItem($teacher);
    }

    public function createTeacher(
        CreateTeacherDTO $dto,
        int $performedByUserId,
    ): CreateTeacherResult {
        $name = $this->normalizeName($dto->name);
        $email = $this->normalizeEmail($dto->email);
        $temporaryPassword = $this->temporaryPasswordGenerator->generate();
        $passwordHash = $this->passwordHasher->hash($temporaryPassword);
        $newUser = new NewUser(
            name: $name,
            email: $email,
            passwordHash: $passwordHash,
            mustChangePassword: true,
            role: UserRole::TEACHER,
            isActive: true,
        );

        $userId = $this->transactionManager->transactional(function () use (
            $email,
            $name,
            $newUser,
            $performedByUserId,
        ): int {
            $existingUser = $this->users->findByEmailIncludingInactive($email);

            if ($existingUser !== null) {
                if (!$existingUser->isActive()) {
                    throw new TeacherEmailAlreadyExistsException(
                        'A user with this email already exists but is inactive.',
                    );
                }

                throw new TeacherEmailAlreadyExistsException(
                    'A user with this email already exists.',
                );
            }

            $userId = $this->users->create($newUser);

            $this->auditLogService->log(
                action: AuditAction::TEACHER_CREATED,
                userId: $performedByUserId,
                entityType: self::AUDIT_ENTITY_TYPE,
                entityId: $userId,
                metadata: [
                    'name' => $name,
                    'email' => $email,
                    'role' => UserRole::TEACHER->value,
                    'isActive' => true,
                ],
            );

            return $userId;
        });

        return new CreateTeacherResult(
            userId: $userId,
            name: $name,
            email: $email,
            role: UserRole::TEACHER,
            temporaryPassword: $temporaryPassword,
        );
    }

    public function updateTeacher(
        int $id,
        UpdateTeacherDTO $dto,
        int $performedByUserId,
    ): UserListItemDTO {
        return $this->transactionManager->transactional(function () use (
            $dto,
            $id,
            $performedByUserId,
        ): UserListItemDTO {
            $teacher = $this->users->findTeacherById($id);

            if ($teacher === null) {
                throw new TeacherNotFoundException('Teacher was not found.');
            }

            $name = $dto->name === null
                ? $teacher->getName()
                : $this->normalizeName($dto->name);
            $email = $dto->email === null
                ? $teacher->getEmail()
                : $this->normalizeEmail($dto->email);

            if ($email !== $teacher->getEmail()) {
                $existingUser = $this->users->findByEmailIncludingInactive($email);

                if (
                    $existingUser !== null
                    && $existingUser->getId() !== $teacher->getId()
                ) {
                    if (!$existingUser->isActive()) {
                        throw new TeacherEmailAlreadyExistsException(
                            'A user with this email already exists but is inactive.',
                        );
                    }

                    throw new TeacherEmailAlreadyExistsException(
                        'A user with this email already exists.',
                    );
                }
            }

            $changes = [];

            if ($name !== $teacher->getName()) {
                $changes['name'] = [
                    'from' => $teacher->getName(),
                    'to' => $name,
                ];
            }

            if ($email !== $teacher->getEmail()) {
                $changes['email'] = [
                    'from' => $teacher->getEmail(),
                    'to' => $email,
                ];
            }

            if ($changes === []) {
                return $this->toUserListItem($teacher);
            }

            $teacher->updateProfile($name, $email);
            $this->users->updateTeacherProfile($teacher);

            $this->auditLogService->log(
                action: AuditAction::TEACHER_UPDATED,
                userId: $performedByUserId,
                entityType: self::AUDIT_ENTITY_TYPE,
                entityId: $teacher->getId(),
                metadata: [
                    'changes' => $changes,
                ],
            );

            return $this->toUserListItem($teacher);
        });
    }

    public function activateTeacher(
        int $id,
        int $performedByUserId,
    ): UserListItemDTO {
        return $this->changeTeacherStatus($id, true, $performedByUserId);
    }

    public function deactivateTeacher(
        int $id,
        int $performedByUserId,
    ): UserListItemDTO {
        return $this->changeTeacherStatus($id, false, $performedByUserId);
    }

    public function resetTeacherPassword(
        int $id,
        int $performedByUserId,
    ): ResetTeacherPasswordResult {
        $teacher = $this->users->findTeacherById($id);

        if ($teacher === null) {
            throw new TeacherNotFoundException('Teacher was not found.');
        }

        $temporaryPassword = $this->temporaryPasswordGenerator->generate();
        $passwordHash = $this->passwordHasher->hash($temporaryPassword);

        $teacher->changePasswordHash($passwordHash);
        $teacher->requirePasswordChange();

        $this->transactionManager->transactional(function () use (
            $performedByUserId,
            $teacher,
        ): void {
            $this->users->save($teacher);
            $revokedRefreshTokens = $this->refreshTokens->revokeAllForUser(
                $teacher->getId(),
            );

            $this->auditLogService->log(
                action: AuditAction::TEACHER_PASSWORD_RESET,
                userId: $performedByUserId,
                entityType: self::AUDIT_ENTITY_TYPE,
                entityId: $teacher->getId(),
                metadata: [
                    'mustChangePassword' => true,
                    'revokedRefreshTokens' => $revokedRefreshTokens,
                ],
            );
        });

        return new ResetTeacherPasswordResult(
            user: $this->toUserListItem($teacher),
            temporaryPassword: $temporaryPassword,
        );
    }

    public function listTeachers(
        ListTeachersDTO $dto,
    ): TeacherListResultDTO
    {
        $totalItems = $this->users->countTeachers();
        $teachers = $this->users->findTeachersPage(
            $dto->pageSize,
            $dto->getOffset(),
        );
        $totalPages = $totalItems === 0
            ? 0
            : (int) ceil($totalItems / $dto->pageSize);

        return new TeacherListResultDTO(
            teachers: array_map(
                fn (User $teacher): UserListItemDTO => $this->toUserListItem($teacher),
                $teachers,
            ),
            pageIndex: $dto->pageIndex,
            pageSize: $dto->pageSize,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }

    private function changeTeacherStatus(
        int $id,
        bool $shouldBeActive,
        int $performedByUserId,
    ): UserListItemDTO {
        return $this->transactionManager->transactional(function () use (
            $id,
            $performedByUserId,
            $shouldBeActive,
        ): UserListItemDTO {
            $teacher = $this->users->findTeacherById($id);

            if ($teacher === null) {
                throw new TeacherNotFoundException('Teacher was not found.');
            }

            if ($teacher->isActive() === $shouldBeActive) {
                return $this->toUserListItem($teacher);
            }

            $wasActive = $teacher->isActive();

            if ($shouldBeActive) {
                $teacher->activate();
            } else {
                $teacher->deactivate();
            }

            $this->users->updateTeacherStatus($teacher);

            $this->auditLogService->log(
                action: $shouldBeActive
                    ? AuditAction::TEACHER_ACTIVATED
                    : AuditAction::TEACHER_DEACTIVATED,
                userId: $performedByUserId,
                entityType: self::AUDIT_ENTITY_TYPE,
                entityId: $teacher->getId(),
                metadata: [
                    'status' => [
                        'from' => $wasActive,
                        'to' => $shouldBeActive,
                    ],
                ],
            );

            return $this->toUserListItem($teacher);
        });
    }

    private function toUserListItem(User $teacher): UserListItemDTO
    {
        return new UserListItemDTO(
            id: $teacher->getId(),
            name: $teacher->getName(),
            email: $teacher->getEmail(),
            role: $teacher->getRole(),
            isActive: $teacher->isActive(),
            mustChangePassword: $teacher->mustChangePassword(),
        );
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Teacher name is required.');
        }

        return $name;
    }

    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Teacher email is invalid.');
        }

        return $email;
    }
}
