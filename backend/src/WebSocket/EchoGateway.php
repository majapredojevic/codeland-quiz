<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use JsonException;
use OpenSwoole\Http\Request;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;

final class EchoGateway implements WebSocketGateway
{
    public function open(Server $server, Request $request): void
    {
        echo "WebSocket connection opened: {$request->fd}\n";
    }

    /**
     * @throws JsonException
     */
    public function message(Server $server, Frame $frame): void
    {
        echo "Received message from {$frame->fd}: {$frame->data}\n";

        $server->push($frame->fd, json_encode([
            'event' => 'server.echo',
            'data' => [
                'message' => $frame->data,
            ],
        ], JSON_THROW_ON_ERROR));
    }

    public function close(Server $server, int $fileDescriptor): void
    {
        echo "WebSocket connection closed: {$fileDescriptor}\n";
    }
}
