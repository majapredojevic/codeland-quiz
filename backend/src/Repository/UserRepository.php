<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\NewUser;
use CodeLandQuiz\Model\User;

interface UserRepository
{
    public function create(NewUser $user): int;

    public function findById(int $id): ?User;

    public function findTeacherById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    public function findByEmailIncludingInactive(string $email): ?User;

    public function save(User $user): void;

    public function updateTeacherProfile(User $user): void;

    /**
     * @return User[]
     */
    public function findTeachersPage(
        int $limit,
        int $offset,
    ): array;

    public function countTeachers(): int;
}
