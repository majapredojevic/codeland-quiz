<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use Closure;
use CodeLandQuiz\Auth\Exception\LoginRateLimitedException;
use InvalidArgumentException;

final class LoginIpRateLimiter
{
    private const MAXIMUM_TRACKED_CLIENTS = 4096;

    /**
     * @var array<string, array<string, int>>
     */
    private array $attemptsByClient = [];

    private int $reservationSequence = 0;

    private Closure $clock;

    public function __construct(
        private readonly int $attemptLimit,
        private readonly int $windowSeconds,
        ?Closure $clock = null,
    ) {
        if ($this->attemptLimit < 1 || $this->windowSeconds < 1) {
            throw new InvalidArgumentException(
                'Login IP rate-limit values must be greater than zero.',
            );
        }

        $this->clock = $clock ?? static fn (): int => time();
    }

    public function reserve(string $clientIdentifier): string
    {
        $now = ($this->clock)();
        $this->pruneClient($clientIdentifier, $now);

        if (!isset($this->attemptsByClient[$clientIdentifier])) {
            $this->pruneAll($now);

            if (count($this->attemptsByClient) >= self::MAXIMUM_TRACKED_CLIENTS) {
                throw new LoginRateLimitedException(
                    retryAfterSeconds: $this->windowSeconds,
                );
            }
        }

        $attempts = $this->attemptsByClient[$clientIdentifier] ?? [];

        if (count($attempts) >= $this->attemptLimit) {
            $oldestAttempt = min($attempts);

            throw new LoginRateLimitedException(
                retryAfterSeconds: max(
                    1,
                    ($oldestAttempt + $this->windowSeconds) - $now,
                ),
            );
        }

        $reservationId = sprintf(
            '%d:%d',
            $now,
            ++$this->reservationSequence,
        );
        $this->attemptsByClient[$clientIdentifier][$reservationId] = $now;

        return $reservationId;
    }

    public function release(
        string $clientIdentifier,
        string $reservationId,
    ): void {
        unset($this->attemptsByClient[$clientIdentifier][$reservationId]);

        if (($this->attemptsByClient[$clientIdentifier] ?? []) === []) {
            unset($this->attemptsByClient[$clientIdentifier]);
        }
    }

    private function pruneAll(int $now): void
    {
        foreach (array_keys($this->attemptsByClient) as $clientIdentifier) {
            $this->pruneClient($clientIdentifier, $now);
        }
    }

    private function pruneClient(string $clientIdentifier, int $now): void
    {
        if (!isset($this->attemptsByClient[$clientIdentifier])) {
            return;
        }

        $windowStart = $now - $this->windowSeconds;
        $this->attemptsByClient[$clientIdentifier] = array_filter(
            $this->attemptsByClient[$clientIdentifier],
            static fn (int $attemptedAt): bool => $attemptedAt > $windowStart,
        );

        if ($this->attemptsByClient[$clientIdentifier] === []) {
            unset($this->attemptsByClient[$clientIdentifier]);
        }
    }
}
