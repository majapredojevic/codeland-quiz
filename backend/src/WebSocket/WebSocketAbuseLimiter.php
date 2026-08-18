<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use Closure;
use CodeLandQuiz\WebSocket\Exception\WebSocketRateLimitExceededException;
use InvalidArgumentException;

final class WebSocketAbuseLimiter
{
    private const MAXIMUM_TRACKED_CLIENTS = 4096;

    /**
     * @var array<int, string>
     */
    private array $clientByConnection = [];

    /**
     * @var array<int, int>
     */
    private array $authenticationAttemptsByConnection = [];

    /**
     * @var array<string, int[]>
     */
    private array $authenticationAttemptsByClient = [];

    /**
     * @var array<int, int[]>
     */
    private array $answerAttemptsByConnection = [];

    private Closure $clock;

    public function __construct(
        private readonly int $authenticationAttemptLimit,
        private readonly int $authenticationIpAttemptLimit,
        private readonly int $authenticationIpWindowSeconds,
        private readonly int $answerAttemptLimit,
        private readonly int $answerAttemptWindowSeconds,
        ?Closure $clock = null,
    ) {
        if (
            $this->authenticationAttemptLimit < 1
            || $this->authenticationIpAttemptLimit < 1
            || $this->authenticationIpWindowSeconds < 1
            || $this->answerAttemptLimit < 1
            || $this->answerAttemptWindowSeconds < 1
        ) {
            throw new InvalidArgumentException(
                'WebSocket abuse limits must be greater than zero.',
            );
        }

        $this->clock = $clock ?? static fn (): int => time();
    }

    public function registerConnection(
        int $fileDescriptor,
        string $clientIdentifier,
    ): void {
        $this->removeConnection($fileDescriptor);
        $this->clientByConnection[$fileDescriptor] = $clientIdentifier;
    }

    public function recordAuthenticationAttempt(int $fileDescriptor): void
    {
        $clientIdentifier = $this->clientByConnection[$fileDescriptor] ?? null;

        if ($clientIdentifier === null) {
            throw new WebSocketRateLimitExceededException(
                'WebSocket connection state was not found.',
            );
        }

        $connectionAttempts =
            $this->authenticationAttemptsByConnection[$fileDescriptor] ?? 0;

        if ($connectionAttempts >= $this->authenticationAttemptLimit) {
            throw new WebSocketRateLimitExceededException(
                'WebSocket authentication attempt limit reached.',
            );
        }

        $now = ($this->clock)();
        $this->pruneAuthenticationClients($now);
        $clientAttempts =
            $this->authenticationAttemptsByClient[$clientIdentifier] ?? [];

        if (count($clientAttempts) >= $this->authenticationIpAttemptLimit) {
            throw new WebSocketRateLimitExceededException(
                'WebSocket authentication IP limit reached.',
            );
        }

        if (
            !isset($this->authenticationAttemptsByClient[$clientIdentifier])
            && count($this->authenticationAttemptsByClient)
                >= self::MAXIMUM_TRACKED_CLIENTS
        ) {
            throw new WebSocketRateLimitExceededException(
                'WebSocket authentication tracking limit reached.',
            );
        }

        $this->authenticationAttemptsByConnection[$fileDescriptor] =
            $connectionAttempts + 1;
        $this->authenticationAttemptsByClient[$clientIdentifier][] = $now;
    }

    public function recordAnswerAttempt(int $fileDescriptor): void
    {
        $now = ($this->clock)();
        $windowStart = $now - $this->answerAttemptWindowSeconds;
        $attempts = array_values(array_filter(
            $this->answerAttemptsByConnection[$fileDescriptor] ?? [],
            static fn (int $attemptedAt): bool => $attemptedAt > $windowStart,
        ));

        if (count($attempts) >= $this->answerAttemptLimit) {
            throw new WebSocketRateLimitExceededException(
                'WebSocket answer attempt limit reached.',
            );
        }

        $attempts[] = $now;
        $this->answerAttemptsByConnection[$fileDescriptor] = $attempts;
    }

    public function markAuthenticated(int $fileDescriptor): void
    {
        unset($this->authenticationAttemptsByConnection[$fileDescriptor]);
    }

    public function removeConnection(int $fileDescriptor): void
    {
        unset($this->clientByConnection[$fileDescriptor]);
        unset($this->authenticationAttemptsByConnection[$fileDescriptor]);
        unset($this->answerAttemptsByConnection[$fileDescriptor]);
    }

    private function pruneAuthenticationClients(int $now): void
    {
        $windowStart = $now - $this->authenticationIpWindowSeconds;

        foreach ($this->authenticationAttemptsByClient as $client => $attempts) {
            $attempts = array_values(array_filter(
                $attempts,
                static fn (int $attemptedAt): bool => $attemptedAt > $windowStart,
            ));

            if ($attempts === []) {
                unset($this->authenticationAttemptsByClient[$client]);

                continue;
            }

            $this->authenticationAttemptsByClient[$client] = $attempts;
        }
    }
}
