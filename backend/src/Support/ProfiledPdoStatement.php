<?php

declare(strict_types=1);

namespace CodeLandQuiz\Support;

use CodeLandQuiz\Observability\PerformanceProfiler;
use PDOStatement;

final class ProfiledPdoStatement extends PDOStatement
{
    protected function __construct(
        private readonly PerformanceProfiler $profiler,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $startedAt = hrtime(true);

        try {
            return parent::execute($params);
        } finally {
            $this->profiler->recordSqlExecution($startedAt);
        }
    }
}
