<?php

declare(strict_types=1);

use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;

require __DIR__ . '/vendor/autoload.php';

$server = new Server('0.0.0.0', 9501);

$server->set([
    'worker_num' => 1,
    'enable_coroutine' => true,
]);

$server->on('start', function (Server $server): void {
    echo "CodeLand Quiz OpenSwoole server started on http://localhost:9501\n";
});

$server->on('request', function (Request $request, Response $response): void {
    $path = $request->server['request_uri'] ?? '/';

    $response->header('Content-Type', 'application/json');

    if ($path === '/health') {
        $response->end(json_encode([
            'status' => 'ok',
            'service' => 'codeland-quiz-backend',
            'server' => 'openswoole',
        ]));
        return;
    }

    $response->status(404);
    $response->end(json_encode([
        'error' => 'Not found',
    ]));
});

$server->on('open', function (Server $server, Request $request): void {
    echo "WebSocket connection opened: {$request->fd}\n";
});

$server->on('message', function (Server $server, Frame $frame): void {
    echo "Received message from {$frame->fd}: {$frame->data}\n";

    $server->push($frame->fd, json_encode([
        'event' => 'server.echo',
        'data' => [
            'message' => $frame->data,
        ],
    ]));
});

$server->on('close', function (Server $server, int $fd): void {
    echo "WebSocket connection closed: {$fd}\n";
});

$server->start();
