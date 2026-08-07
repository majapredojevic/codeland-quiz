<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use OpenSwoole\WebSocket\Server;
use Throwable;

final class ParticipantRemovalWebSocketNotifier
{
    private const POLICY_VIOLATION_CLOSE_CODE = 1008;

    public function __construct(
        private readonly Server $server,
        private readonly ParticipantConnectionRegistry $connectionRegistry,
        private readonly ParticipantWebSocketSender $participantSender,
    ) {
    }

    public function notifyAndDisconnect(int $participantId): void
    {
        $fileDescriptor = $this->connectionRegistry
            ->findCurrentFileDescriptorByParticipantId($participantId);

        if ($fileDescriptor === null) {
            return;
        }

        $this->participantSender->send(
            participantId: $participantId,
            type: 'PARTICIPANT_REMOVED',
            payload: [
                'message' =>
                    'You were removed from this quiz session by the host.',
            ],
        );

        $connection = $this->connectionRegistry->findAuthenticated(
            $fileDescriptor,
        );

        if (
            $connection === null
            || $connection->participantId !== $participantId
        ) {
            return;
        }

        $this->connectionRegistry->remove($fileDescriptor);

        try {
            if ($this->server->isEstablished($fileDescriptor)) {
                $this->server->disconnect(
                    $fileDescriptor,
                    self::POLICY_VIOLATION_CLOSE_CODE,
                );
            }
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
        }
    }
}
