<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

use DateTimeImmutable;

final class AuditLog
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        private readonly ?int $id,
        private readonly ?int $userId,
        private readonly string $action,
        private readonly ?string $entityType,
        private readonly ?int $entityId,
        private readonly ?array $metadata,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
