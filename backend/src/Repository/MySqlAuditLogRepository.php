<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\AuditLog;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;

final class MySqlAuditLogRepository implements AuditLogRepository
{
    private const INSERT_SQL = <<<SQL
INSERT INTO audit_logs (
    user_id,
    action,
    entity_type,
    entity_id,
    metadata,
    created_at
) VALUES (
    :user_id,
    :action,
    :entity_type,
    :entity_id,
    :metadata,
    :created_at
)
SQL;

    public function __construct(
        private readonly Database $database,
    ) {}

    public function save(AuditLog $auditLog): void
    {
        $statement = $this->connection()->prepare(self::INSERT_SQL);

        $statement->execute([
            'user_id' => $auditLog->getUserId(),
            'action' => $auditLog->getAction(),
            'entity_type' => $auditLog->getEntityType(),
            'entity_id' => $auditLog->getEntityId(),
            'metadata' => $this->encodeMetadata($auditLog->getMetadata()),
            'created_at' => $this->formatDateTime($auditLog->getCreatedAt()),
        ]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('Audit log was not inserted.');
        }
    }

    private function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function encodeMetadata(?array $metadata): ?string
    {
        if ($metadata === null) {
            return null;
        }

        try {
            return json_encode($metadata, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Audit log metadata could not be encoded.',
                0,
                $exception,
            );
        }
    }

    private function connection(): PDO
    {
        return $this->database->getConnection();
    }
}
