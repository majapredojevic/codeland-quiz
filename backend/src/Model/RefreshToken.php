<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

use DateTimeImmutable;

final class RefreshToken
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $userId,
        private readonly string $tokenHash,
        private readonly DateTimeImmutable $expiresAt,
        private readonly ?DateTimeImmutable $revokedAt = null,
        private readonly ?int $replacedByTokenId = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getReplacedByTokenId(): ?int
    {
        return $this->replacedByTokenId;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}