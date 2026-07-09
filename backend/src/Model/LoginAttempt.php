<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

use DateTimeImmutable;

final class LoginAttempt
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $email,
        private readonly bool $successful,
        private readonly ?string $userAgent,
        private readonly DateTimeImmutable $attemptedAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getAttemptedAt(): DateTimeImmutable
    {
        return $this->attemptedAt;
    }
}