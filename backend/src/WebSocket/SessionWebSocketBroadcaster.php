<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use CodeLandQuiz\Observability\RuntimeLogger;
use OpenSwoole\WebSocket\Server;
use Throwable;

final class SessionWebSocketBroadcaster
{
    public function __construct(
        private readonly Server $server,
        private readonly ParticipantConnectionRegistry $connectionRegistry,
        private readonly WebSocketMessageEncoder $messageEncoder,
        private readonly RuntimeLogger $logger,
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
            $this->logger->error('websocket.broadcast_encoding_failed', [
                'sessionId' => $sessionId,
                'exception' => $throwable::class,
            ]);

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

                $this->logger->warning('websocket.broadcast_push_failed', [
                    'fd' => $fileDescriptor,
                    'connectionId' => $connection->connectionId,
                    'sessionId' => $sessionId,
                    'participantId' => $connection->participantId,
                ]);
            } catch (Throwable $throwable) {
                $this->logger->error('websocket.broadcast_failed', [
                    'fd' => $fileDescriptor,
                    'connectionId' => $connection->connectionId,
                    'sessionId' => $sessionId,
                    'participantId' => $connection->participantId,
                    'exception' => $throwable::class,
                ]);
            }
        }

        return $successfulPushes;
    }
}
