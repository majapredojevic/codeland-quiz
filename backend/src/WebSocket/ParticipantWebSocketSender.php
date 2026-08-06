<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use OpenSwoole\WebSocket\Server;
use Throwable;

final class ParticipantWebSocketSender
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

            error_log(
                sprintf(
                    'WebSocket push failed for participant %d on file descriptor %d.',
                    $participantId,
                    $fileDescriptor,
                ),
            );
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
        }

        return false;
    }
}
