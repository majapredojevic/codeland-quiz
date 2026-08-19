<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use CodeLandQuiz\Observability\RuntimeLogger;
use CodeLandQuiz\Observability\PerformanceProfiler;
use OpenSwoole\WebSocket\Server;
use Throwable;

final class SessionWebSocketBroadcaster
{
    public function __construct(
        private readonly Server $server,
        private readonly ParticipantConnectionRegistry $connectionRegistry,
        private readonly WebSocketMessageEncoder $messageEncoder,
        private readonly RuntimeLogger $logger,
        private readonly ?PerformanceProfiler $profiler = null,
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
            $encode = fn (): string => $this->messageEncoder->encode(
                $type,
                $payload,
            );
            $message = $this->profilesEvent($type)
                && $this->profiler !== null
                    ? $this->profiler->measure(
                        sprintf('broadcast.%s.serialization', $type),
                        $encode,
                    )
                    : $encode();
        } catch (Throwable $throwable) {
            $this->logger->error('websocket.broadcast_encoding_failed', [
                'sessionId' => $sessionId,
                'exception' => $throwable::class,
            ]);

            return 0;
        }

        $successfulPushes = 0;
        $pushFailures = 0;
        $fileDescriptors = $this->connectionRegistry
            ->findSessionFileDescriptors($sessionId);
        $profiled = $this->profiler !== null && $this->profilesEvent($type);
        $broadcastStartedAt = $profiled ? hrtime(true) : null;

        if ($profiled) {
            $this->profiler?->increment(
                sprintf('broadcast.%s.targets', $type),
                count($fileDescriptors),
            );
        }

        foreach ($fileDescriptors as $fileDescriptor) {
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
                $pushFailures++;
            } catch (Throwable $throwable) {
                $pushFailures++;
                $this->logger->error('websocket.broadcast_failed', [
                    'fd' => $fileDescriptor,
                    'connectionId' => $connection->connectionId,
                    'sessionId' => $sessionId,
                    'participantId' => $connection->participantId,
                    'exception' => $throwable::class,
                ]);
            }
        }

        if ($broadcastStartedAt !== null) {
            $this->profiler?->recordDuration(
                sprintf('broadcast.%s.loop', $type),
                $broadcastStartedAt,
            );
            $this->profiler?->increment(
                sprintf('broadcast.%s.successes', $type),
                $successfulPushes,
            );
            $this->profiler?->increment(
                sprintf('broadcast.%s.failures', $type),
                $pushFailures,
            );
        }

        return $successfulPushes;
    }

    private function profilesEvent(string $type): bool
    {
        return in_array($type, [
            'QUESTION_STARTED',
            'QUESTION_CLOSED',
            'LEADERBOARD_UPDATED',
            'GAME_FINISHED',
        ], true);
    }
}
