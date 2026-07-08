<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

final class User
{
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly string $email,
        private string $passwordHash,
        private readonly UserRole $role,
        private readonly bool $isActive,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function changePasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function canUseNormalLogin(): bool
    {
        return $this->role === UserRole::ADMIN || $this->role === UserRole::TEACHER;
    }
}
