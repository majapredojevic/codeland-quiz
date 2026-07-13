<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

final class User
{
    public function __construct(
        private readonly int $id,
        private string $name,
        private string $email,
        private string $passwordHash,
        private bool $mustChangePassword,
        private readonly UserRole $role,
        private bool $isActive,
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

    public function mustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function activate(): void
    {
        $this->isActive = true;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }

    public function updateProfile(
        string $name,
        string $email,
    ): void {
        $this->name = $name;
        $this->email = $email;
    }

    public function changePasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function requirePasswordChange(): void
    {
        $this->mustChangePassword = true;
    }

    public function passwordChanged(): void
    {
        $this->mustChangePassword = false;
    }

    public function canUseNormalLogin(): bool
    {
        return $this->role === UserRole::ADMIN || $this->role === UserRole::TEACHER;
    }
}
