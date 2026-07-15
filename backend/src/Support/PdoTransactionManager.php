<?php

declare(strict_types=1);

namespace CodeLandQuiz\Support;

use RuntimeException;
use Throwable;

final readonly class PdoTransactionManager implements TransactionManager
{
    public function __construct(
        private Database $database,
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

        $connection->beginTransaction();

        try {
            $result = $operation();
            $connection->commit();

            return $result;
        } catch (Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $throwable;
        }
    }
}
