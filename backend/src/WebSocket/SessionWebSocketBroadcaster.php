<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use OpenSwoole\WebSocket\Server;
use Throwable;

final class SessionWebSocketBroadcaster
{
    public function __construct(
        private readonly Server $server,
        private readonly ParticipantConnectionRegistry $connectionRegistry,
        private readonly WebSocketMessageEncoder $messageEncoder,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function broadcast(int $sessionId, string $type, array $payload): int
    {
        if ($type === 'QUESTION_STARTED') {
            $this->connectionRegistry->clearAcceptedAnswersForSession(
                $sessionId,
            );
        }

        try {
            $message = $this->messageEncoder->encode($type, $payload);
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());

            return 0;
        }

        $successfulPushes = 0;

        foreach (
            $this->connectionRegistry->findSessionFileDescriptors($sessionId)
            as $fileDescriptor
        ) {
            $connection = $this->connectionRegistry->findAuthenticated(
                $fileDescriptor,
            );

            if (
                $connection === null
                || $connection->sessionId !== $sessionId
                || !$this->server->isEstablished($fileDescriptor)
            ) {
                continue;
            }

            try {
                if ($this->server->push($fileDescriptor, $message)) {
                    $successfulPushes++;

                    continue;
                }

                error_log(
                    sprintf(
                        'WebSocket push failed for file descriptor %d.',
                        $fileDescriptor,
                    ),
                );
            } catch (Throwable $throwable) {
                error_log($throwable->getMessage());
            }
        }

        return $successfulPushes;
    }
}
