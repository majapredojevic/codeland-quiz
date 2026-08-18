<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use CodeLandQuiz\Observability\RuntimeLogger;
use JsonException;
use OpenSwoole\Http\Request;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;

final readonly class EchoGateway implements WebSocketGateway
{
    public function __construct(
        private RuntimeLogger $logger,
    ) {
    }

    public function open(Server $server, Request $request): void
    {
        $this->logger->debug('websocket.echo_opened', [
            'fd' => (int) $request->fd,
        ]);
    }

    /**
     * @throws JsonException
     */
    public function message(Server $server, Frame $frame): void
    {
        $this->logger->debug('websocket.echo_frame_received', [
            'fd' => $frame->fd,
            'count' => strlen($frame->data),
        ]);

        $server->push($frame->fd, json_encode([
            'event' => 'server.echo',
            'data' => [
                'message' => $frame->data,
            ],
        ], JSON_THROW_ON_ERROR));
    }

    public function close(Server $server, int $fileDescriptor): void
    {
        $this->logger->debug('websocket.echo_closed', [
            'fd' => $fileDescriptor,
        ]);
    }
}
