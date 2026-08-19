<?php

declare(strict_types=1);

namespace CodeLandQuiz\Support;

use CodeLandQuiz\Observability\PerformanceProfiler;
use OpenSwoole\Coroutine;
use PDO;
use PDOException;
use Throwable;

final class Database
{
    private const COROUTINE_CONNECTION_KEY = 'codeland_quiz.database.connection';

    private const PDO_OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    private ?PDO $fallbackConnection = null;

    public function __construct(
        private readonly Environment $environment,
        private readonly ?PerformanceProfiler $profiler = null,
    ) {
    }

    public function getConnection(): PDO
    {
        $this->profiler?->recordDatabaseConnectionRequested();

        if (Coroutine::getCid() >= 0) {
            $context = Coroutine::getContext();
            $connection = $context[self::COROUTINE_CONNECTION_KEY] ?? null;

            if ($connection instanceof PDO) {
                return $connection;
            }

            $connection = $this->connect();
            $context[self::COROUTINE_CONNECTION_KEY] = $connection;

            return $connection;
        }

        if ($this->fallbackConnection === null) {
            $this->fallbackConnection = $this->connect();
        }

        return $this->fallbackConnection;
    }

    public function isReady(): bool
    {
        try {
            return (int) $this->getConnection()
                ->query('SELECT 1')
                ->fetchColumn() === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function connect(): PDO
    {
        $connectionStartedAt = $this->profiler?->start();

        try {
            $constructorStartedAt = $this->profiler?->start();
            $connection = new PDO(
                $this->createDsn(),
                $this->environment->get('DB_USERNAME'),
                $this->environment->get('DB_PASSWORD'),
                self::PDO_OPTIONS,
            );
            if ($constructorStartedAt !== null) {
                $this->profiler?->recordDuration(
                    'database.connection.pdo_constructor',
                    $constructorStartedAt,
                );
                $connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, [
                    ProfiledPdoStatement::class,
                    [$this->profiler],
                ]);
            }

            $initializationStartedAt = $this->profiler?->start();

            try {
                $connection->exec(
                    "SET SESSION time_zone = '+00:00'",
                );
            } finally {
                if ($initializationStartedAt !== null) {
                    $this->profiler?->recordDuration(
                        'database.connection.session_initialization',
                        $initializationStartedAt,
                    );
                }
            }

            if ($connectionStartedAt !== null) {
                $this->profiler?->recordPdoCreation($connectionStartedAt);
            }

            return $connection;
        } catch (PDOException $exception) {
            throw new DatabaseException(
                'Unable to connect to the database.',
                0,
                $exception,
            );
        }
    }

    private function createDsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->environment->get('DB_HOST'),
            $this->environment->getInt('DB_PORT'),
            $this->environment->get('DB_DATABASE'),
        );
    }
}
