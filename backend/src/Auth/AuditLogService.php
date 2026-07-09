<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use CodeLandQuiz\Model\AuditAction;
use CodeLandQuiz\Model\AuditLog;
use CodeLandQuiz\Repository\AuditLogRepository;
use DateTimeImmutable;

final readonly class AuditLogService
{
    public function __construct(
        private readonly AuditLogRepository $auditLogs,
    ) {
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function log(
        AuditAction $action,
        ?int $userId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $metadata = null,
    ): void {
        $this->auditLogs->save(new AuditLog(
            id: null,
            userId: $userId,
            action: $action->value,
            entityType: $entityType,
            entityId: $entityId,
            metadata: $metadata,
            createdAt: new DateTimeImmutable(),
        ));
    }
}