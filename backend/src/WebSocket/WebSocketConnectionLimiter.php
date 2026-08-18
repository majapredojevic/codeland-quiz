<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use CodeLandQuiz\WebSocket\Exception\WebSocketRateLimitExceededException;
use InvalidArgumentException;

final class WebSocketConnectionLimiter
{
    /**
     * @var array<int, array{clientIdentifier: string, pending: bool}>
     */
    private array $connections = [];

    /**
     * @var array<string, int>
     */
    private array $connectionsByClient = [];

    private int $pendingConnections = 0;

    public function __construct(
        private readonly int $globalLimit,
        private readonly int $pendingLimit,
        private readonly int $perIpLimit,
    ) {
        if (
            $this->globalLimit < 1
            || $this->pendingLimit < 1
            || $this->perIpLimit < 1
            || $this->pendingLimit > $this->globalLimit
            || $this->perIpLimit > $this->globalLimit
        ) {
            throw new InvalidArgumentException(
                'WebSocket connection limits are invalid.',
            );
        }
    }

    public function register(
        int $fileDescriptor,
        string $clientIdentifier,
        bool $pendingAuthentication,
    ): void {
        $this->remove($fileDescriptor);

        if (
            count($this->connections) >= $this->globalLimit
            || ($this->connectionsByClient[$clientIdentifier] ?? 0)
                >= $this->perIpLimit
            || ($pendingAuthentication
                && $this->pendingConnections >= $this->pendingLimit)
        ) {
            throw new WebSocketRateLimitExceededException(
                'WebSocket connection limit reached.',
            );
        }

        $this->connections[$fileDescriptor] = [
            'clientIdentifier' => $clientIdentifier,
            'pending' => $pendingAuthentication,
        ];
        $this->connectionsByClient[$clientIdentifier] =
            ($this->connectionsByClient[$clientIdentifier] ?? 0) + 1;

        if ($pendingAuthentication) {
            $this->pendingConnections++;
        }
    }

    public function markAuthenticated(int $fileDescriptor): void
    {
        if (!($this->connections[$fileDescriptor]['pending'] ?? false)) {
            return;
        }

        $this->connections[$fileDescriptor]['pending'] = false;
        $this->pendingConnections = max(0, $this->pendingConnections - 1);
    }

    public function remove(int $fileDescriptor): void
    {
        $connection = $this->connections[$fileDescriptor] ?? null;

        if ($connection === null) {
            return;
        }

        unset($this->connections[$fileDescriptor]);

        if ($connection['pending']) {
            $this->pendingConnections = max(0, $this->pendingConnections - 1);
        }

        $clientIdentifier = $connection['clientIdentifier'];
        $remaining = ($this->connectionsByClient[$clientIdentifier] ?? 1) - 1;

        if ($remaining < 1) {
            unset($this->connectionsByClient[$clientIdentifier]);

            return;
        }

        $this->connectionsByClient[$clientIdentifier] = $remaining;
    }
}
