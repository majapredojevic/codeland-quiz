<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use InvalidArgumentException;

final readonly class WebSocketOriginPolicy
{
    /**
     * @var array<string, true>
     */
    private array $allowedOrigins;

    /**
     * @param string[] $allowedOrigins
     */
    public function __construct(
        array $allowedOrigins,
        bool $requireHttps = false,
    ) {
        $normalizedOrigins = [];

        foreach ($allowedOrigins as $allowedOrigin) {
            $normalizedOrigin = $this->normalize($allowedOrigin);

            if ($normalizedOrigin === null) {
                throw new InvalidArgumentException(
                    'Configured WebSocket origin is invalid.',
                );
            }

            if (
                $requireHttps
                && !str_starts_with($normalizedOrigin, 'https://')
            ) {
                throw new InvalidArgumentException(
                    'Production WebSocket origins must use HTTPS.',
                );
            }

            $normalizedOrigins[$normalizedOrigin] = true;
        }

        if ($normalizedOrigins === []) {
            throw new InvalidArgumentException(
                'At least one WebSocket origin must be configured.',
            );
        }

        $this->allowedOrigins = $normalizedOrigins;
    }

    public function allows(mixed $origin): bool
    {
        if (!is_string($origin) || trim($origin) !== $origin) {
            return false;
        }

        $normalizedOrigin = $this->normalize($origin);

        return $normalizedOrigin !== null
            && isset($this->allowedOrigins[$normalizedOrigin]);
    }

    private function normalize(string $origin): ?string
    {
        if (
            $origin === ''
            || strlen($origin) > 2048
            || str_contains($origin, "\r")
            || str_contains($origin, "\n")
        ) {
            return null;
        }

        $parts = parse_url($origin);

        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (
            !in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')
        ) {
            return null;
        }

        $port = $parts['port'] ?? null;

        if (
            ($scheme === 'http' && $port === 80)
            || ($scheme === 'https' && $port === 443)
        ) {
            $port = null;
        }

        if (str_contains($host, ':')) {
            $host = '[' . trim($host, '[]') . ']';
        }

        return sprintf(
            '%s://%s%s',
            $scheme,
            $host,
            $port === null ? '' : ':' . $port,
        );
    }
}
