<?php

declare(strict_types=1);

namespace CodeLandQuiz\Admin;

use CodeLandQuiz\Auth\PasswordHasher;
use CodeLandQuiz\Auth\TemporaryPasswordGenerator;
use CodeLandQuiz\DTO\CreateTeacherDTO;
use CodeLandQuiz\DTO\CreateTeacherResult;
use CodeLandQuiz\DTO\ListTeachersDTO;
use CodeLandQuiz\DTO\TeacherListResultDTO;
use CodeLandQuiz\DTO\UserListItemDTO;
use CodeLandQuiz\Model\NewUser;
use CodeLandQuiz\Model\User;
use CodeLandQuiz\Model\UserRole;
use CodeLandQuiz\Repository\UserRepository;
use CodeLandQuiz\Admin\Exception\TeacherNotFoundException;
use InvalidArgumentException;

final readonly class UserManagementService
{
    public function __construct(
        private UserRepository $users,
        private TemporaryPasswordGenerator $temporaryPasswordGenerator,
        private PasswordHasher $passwordHasher,
    ) {}

    public function getTeacher(int $id): UserListItemDTO
    {
        $teacher = $this->users->findTeacherById($id);

        if ($teacher === null) {
            throw new TeacherNotFoundException('Teacher was not found.');
        }

        return $this->toUserListItem($teacher);
    }

    public function createTeacher(CreateTeacherDTO $dto): CreateTeacherResult
    {
        $name = $this->normalizeName($dto->name);
        $email = $this->normalizeEmail($dto->email);

        $existingUser = $this->users->findByEmailIncludingInactive($email);

        if ($existingUser !== null) {
            if (!$existingUser->isActive()) {
                throw new InvalidArgumentException(
                    'A user with this email already exists but is inactive.',
                );
            }

            throw new InvalidArgumentException(
                'A user with this email already exists.',
            );
        }

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

        $userId = $this->users->create($newUser);

        return new CreateTeacherResult(
            userId: $userId,
            name: $name,
            email: $email,
            role: UserRole::TEACHER,
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
                fn(User $teacher): UserListItemDTO => $this->toUserListItem($teacher),
                $teachers,
            ),
            pageIndex: $dto->pageIndex,
            pageSize: $dto->pageSize,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
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
