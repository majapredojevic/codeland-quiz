<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use CodeLandQuiz\Observability\PerformanceProfiler;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\WebSocket\Server;

final readonly class WebSocketHandshakeHandler
{
    private const HANDSHAKE_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public function __construct(
        private WebSocketGatewayRouter $gatewayRouter,
        private ?PerformanceProfiler $profiler = null,
    ) {
    }

    public function handle(
        Server $server,
        Request $request,
        Response $response,
    ): bool {
        if ($this->profiler === null) {
            return $this->performHandshake($server, $request, $response);
        }

        return $this->profiler->measure(
            'ws_handshake.total',
            fn (): bool => $this->performHandshake(
                $server,
                $request,
                $response,
            ),
        );
    }

    private function performHandshake(
        Server $server,
        Request $request,
        Response $response,
    ): bool {
        $webSocketKey = $request->header['sec-websocket-key'] ?? null;
        $webSocketVersion = $request->header['sec-websocket-version'] ?? null;

        if (
            !$this->gatewayRouter->allowsHandshake($request)
            || !is_string($webSocketKey)
            || !$this->isValidWebSocketKey($webSocketKey)
            || $webSocketVersion !== '13'
        ) {
            $response->status(403);
            $response->end();

            return false;
        }

        $upgrade = function () use ($response, $webSocketKey): void {
            $response->header('Upgrade', 'websocket');
            $response->header('Connection', 'Upgrade');
            $response->header(
                'Sec-WebSocket-Accept',
                base64_encode(sha1(
                    $webSocketKey . self::HANDSHAKE_GUID,
                    true,
                )),
            );
            $response->header('Sec-WebSocket-Version', '13');
            $response->status(101);
            $response->end();
        };

        if ($this->profiler !== null) {
            $this->profiler->measure('ws_handshake.upgrade_work', $upgrade);
        } else {
            $upgrade();
        }

        $server->defer(function () use ($server, $request): void {
            $this->gatewayRouter->open($server, $request);
        });

        return true;
    }

    private function isValidWebSocketKey(string $key): bool
    {
        if (strlen($key) > 64) {
            return false;
        }

        $decodedKey = base64_decode($key, true);

        return $decodedKey !== false && strlen($decodedKey) === 16;
    }
}
