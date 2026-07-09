<?php

declare(strict_types=1);

namespace CodeLandQuiz\Support;

use PDO;
use PDOException;

final class Database
{
    private ?PDO $connection = null;

    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            $this->connection = $this->connect();
        }

        return $this->connection;
    }

    private function connect(): PDO
    {
        $host = $this->getEnv('DB_HOST');
        $port = $this->getEnv('DB_PORT', '3306');
        $database = $this->getEnv('DB_DATABASE');
        $username = $this->getEnv('DB_USERNAME');
        $password = $this->getEnv('DB_PASSWORD');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $database,
        );

        try {
            return new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new DatabaseException('Unable to connect to the database.', 0, $exception);
        }
    }

    private function getEnv(string $key, ?string $default = null): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === '') {
            if ($default !== null) {
                return $default;
            }

            throw new DatabaseException(sprintf('Missing database environment variable: %s', $key));
        }

        return (string) $value;
    }
}
