<?php

declare(strict_types=1);

namespace CodeLandQuiz\Http;

use InvalidArgumentException;

final readonly class RequestBody
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private array $data,
    ) {
    }

    public function getString(string $key): string
    {
        $value = $this->data[$key] ?? null;

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Request field "%s" is required and must be a string.', $key));
        }

        return $value;
    }

    public function getOptionalString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Request field "%s" must be a string.', $key));
        }

        return $value;
    }
}
