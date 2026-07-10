<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

final readonly class NewUser
{
    public function __construct(
        private string $name,
        private string $email,
        private string $passwordHash,
        private bool $mustChangePassword,
        private UserRole $role,
        private bool $isActive = true,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
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
}