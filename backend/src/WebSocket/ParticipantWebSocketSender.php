<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use CodeLandQuiz\Observability\RuntimeLogger;
use OpenSwoole\WebSocket\Server;
use Throwable;

final class ParticipantWebSocketSender
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
    public function send(
        int $participantId,
        string $type,
        array $payload,
    ): bool {
        $fileDescriptor = $this->connectionRegistry
            ->findCurrentFileDescriptorByParticipantId($participantId);

        if ($fileDescriptor === null) {
            return false;
        }

        $connection = $this->connectionRegistry->findAuthenticated(
            $fileDescriptor,
        );

        if (
            $connection === null
            || $connection->participantId !== $participantId
        ) {
            return false;
        }

        try {
            if (!$this->server->isEstablished($fileDescriptor)) {
                return false;
            }

            $message = $this->messageEncoder->encode($type, $payload);

            if ($this->server->push($fileDescriptor, $message)) {
                return true;
            }

            $this->logger->warning('websocket.participant_push_failed', [
                'fd' => $fileDescriptor,
                'connectionId' => $connection->connectionId,
                'sessionId' => $connection->sessionId,
                'participantId' => $participantId,
            ]);
        } catch (Throwable $throwable) {
            $this->logger->error('websocket.participant_send_failed', [
                'fd' => $fileDescriptor,
                'connectionId' => $connection->connectionId,
                'sessionId' => $connection->sessionId,
                'participantId' => $participantId,
                'exception' => $throwable::class,
            ]);
        }

        return false;
    }
}
