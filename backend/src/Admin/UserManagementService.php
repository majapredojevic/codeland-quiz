<?php

declare(strict_types=1);

namespace CodeLandQuiz\Admin;

use CodeLandQuiz\Auth\PasswordHasher;
use CodeLandQuiz\Auth\TemporaryPasswordGenerator;
use CodeLandQuiz\DTO\CreateTeacherDTO;
use CodeLandQuiz\DTO\CreateTeacherResult;
use CodeLandQuiz\Model\NewUser;
use CodeLandQuiz\Model\UserRole;
use CodeLandQuiz\Repository\UserRepository;
use InvalidArgumentException;

final readonly class UserManagementService
{
    public function __construct(
        private UserRepository $users,
        private TemporaryPasswordGenerator $temporaryPasswordGenerator,
        private PasswordHasher $passwordHasher,
    ) {}

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

        $email = filter_var(
            $email,
            FILTER_SANITIZE_EMAIL,
        );

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Teacher email is invalid.');
        }

        return $email;
    }
}
