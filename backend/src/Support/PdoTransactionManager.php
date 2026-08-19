<?php

declare(strict_types=1);

namespace CodeLandQuiz\Support;

use CodeLandQuiz\Observability\PerformanceProfiler;
use RuntimeException;
use Throwable;

final readonly class PdoTransactionManager implements TransactionManager
{
    public function __construct(
        private Database $database,
        private ?PerformanceProfiler $profiler = null,
    ) {}

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed
    {
        $connection = $this->database->getConnection();

        if ($connection->inTransaction()) {
            throw new RuntimeException('A database transaction is already active.');
        }

        $beginStartedAt = $this->profiler?->start();

        try {
            $connection->beginTransaction();
        } finally {
            if ($beginStartedAt !== null) {
                $this->profiler?->recordTransactionControl(
                    'begin',
                    $beginStartedAt,
                );
            }
        }

        try {
            $result = $operation();
            $commitStartedAt = $this->profiler?->start();

            try {
                $connection->commit();
            } finally {
                if ($commitStartedAt !== null) {
                    $this->profiler?->recordTransactionControl(
                        'commit',
                        $commitStartedAt,
                    );
                }
            }

            return $result;
        } catch (Throwable $throwable) {
            if ($connection->inTransaction()) {
                $rollbackStartedAt = $this->profiler?->start();

                try {
                    $connection->rollBack();
                } finally {
                    if ($rollbackStartedAt !== null) {
                        $this->profiler?->recordTransactionControl(
                            'rollback',
                            $rollbackStartedAt,
                        );
                    }
                }
            }

            throw $throwable;
        }
    }
}
