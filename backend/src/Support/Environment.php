<?php

declare(strict_types=1);

namespace CodeLandQuiz\Support;

use Dotenv\Dotenv;
use RuntimeException;

final class Environment
{
    /**
     * @var array<string, bool>
     */
    private static array $loadedPaths = [];

    public function __construct(
        private readonly string $projectRootPath,
    ) {
        $this->load();
    }

    public function get(string $key): string
    {
        $value = $this->value($key);

        if ($value === null || $value === '') {
            throw new RuntimeException(sprintf('Missing required environment variable: %s', $key));
        }

        return $value;
    }

    public function getInt(string $key): int
    {
        $value = $this->get($key);

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException(sprintf('Environment variable must be an integer: %s', $key));
        }

        return (int) $value;
    }

    public function getBool(string $key): bool
    {
        $value = filter_var($this->get($key), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($value === null) {
            throw new RuntimeException(sprintf('Environment variable must be a boolean: %s', $key));
        }

        return $value;
    }

    public function has(string $key): bool
    {
        $value = $this->value($key);

        return $value !== null && $value !== '';
    }

    private function load(): void
    {
        $projectRootPath = rtrim($this->projectRootPath, DIRECTORY_SEPARATOR);

        if (self::$loadedPaths[$projectRootPath] ?? false) {
            return;
        }

        Dotenv::createImmutable($projectRootPath)->safeLoad();

        self::$loadedPaths[$projectRootPath] = true;
    }

    private function value(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return null;
        }

        return (string) $value;
    }
}
