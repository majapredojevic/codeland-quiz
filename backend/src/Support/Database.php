<?php

declare(strict_types=1);

namespace CodeLandQuiz\Support;

use PDO;
use PDOException;

final class Database
{
    private const PDO_OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    private ?PDO $connection = null;

    public function __construct(
        private readonly Environment $environment,
    ) {
    }

    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            $this->connection = $this->connect();
        }

        return $this->connection;
    }

    private function connect(): PDO
    {
        try {
            return new PDO(
                $this->createDsn(),
                $this->environment->get('DB_USERNAME'),
                $this->environment->get('DB_PASSWORD'),
                self::PDO_OPTIONS,
            );
        } catch (PDOException $exception) {
            throw new DatabaseException('Unable to connect to the database.', 0, $exception);
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
