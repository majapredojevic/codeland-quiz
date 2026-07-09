<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\AuditLog;

interface AuditLogRepository
{
    public function save(AuditLog $auditLog): void;
}
