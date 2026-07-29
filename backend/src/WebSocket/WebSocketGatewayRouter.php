<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use OpenSwoole\Http\Request;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;
use Throwable;

final class WebSocketGatewayRouter
{
    private const GAME_PATH = '/ws/game';
    private const ECHO_PATH = '/ws/echo';
    private const POLICY_VIOLATION_CLOSE_CODE = 1008;

    /**
     * @var array<int, WebSocketGateway>
     */
    private array $gatewaysByFileDescriptor = [];

    public function __construct(
        private readonly ParticipantWebSocketGateway $participantGateway,
        private readonly EchoGateway $echoGateway,
        private readonly WebSocketMessageEncoder $messageEncoder,
    ) {
    }

    public function open(Server $server, Request $request): void
    {
        $fileDescriptor = (int) $request->fd;
        $gateway = $this->gatewayForRequest($request);

        if ($gateway === null) {
            $this->rejectUnknownPath($server, $fileDescriptor);

            return;
        }

        $this->gatewaysByFileDescriptor[$fileDescriptor] = $gateway;

        try {
            $gateway->open($server, $request);
        } catch (Throwable $throwable) {
            unset($this->gatewaysByFileDescriptor[$fileDescriptor]);
            error_log($throwable->getMessage());
            $this->rejectWithInternalError($server, $fileDescriptor);
        }
    }

    public function message(Server $server, Frame $frame): void
    {
        $gateway = $this->gatewaysByFileDescriptor[$frame->fd] ?? null;

        if ($gateway === null) {
            $this->rejectUnknownPath($server, $frame->fd);

            return;
        }

        try {
            $gateway->message($server, $frame);
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            $this->rejectWithInternalError($server, $frame->fd);
        }
    }

    public function close(Server $server, int $fileDescriptor): void
    {
        $gateway = $this->gatewaysByFileDescriptor[$fileDescriptor] ?? null;
        unset($this->gatewaysByFileDescriptor[$fileDescriptor]);

        if ($gateway === null) {
            return;
        }

        try {
            $gateway->close($server, $fileDescriptor);
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
        }
    }

    private function gatewayForRequest(Request $request): ?WebSocketGateway
    {
        $path = parse_url(
            (string) ($request->server['request_uri'] ?? ''),
            PHP_URL_PATH,
        );

        return match ($path) {
            self::GAME_PATH => $this->participantGateway,
            self::ECHO_PATH => $this->echoGateway,
            default => null,
        };
    }

    private function rejectUnknownPath(
        Server $server,
        int $fileDescriptor,
    ): void {
        $this->pushError(
            server: $server,
            fileDescriptor: $fileDescriptor,
            code: 'UNKNOWN_WEBSOCKET_PATH',
            message: 'WebSocket path is not supported.',
        );
        $this->disconnect($server, $fileDescriptor);
    }

    private function rejectWithInternalError(
        Server $server,
        int $fileDescriptor,
    ): void {
        $this->pushError(
            server: $server,
            fileDescriptor: $fileDescriptor,
            code: 'INTERNAL_ERROR',
            message: 'An unexpected server error occurred.',
        );
        $this->disconnect($server, $fileDescriptor);
    }

    private function pushError(
        Server $server,
        int $fileDescriptor,
        string $code,
        string $message,
    ): void {
        if (!$server->isEstablished($fileDescriptor)) {
            return;
        }

        $server->push(
            $fileDescriptor,
            $this->messageEncoder->encode('ERROR', [
                'code' => $code,
                'message' => $message,
            ]),
        );
    }

    private function disconnect(Server $server, int $fileDescriptor): void
    {
        if (!$server->isEstablished($fileDescriptor)) {
            return;
        }

        $server->disconnect(
            $fileDescriptor,
            self::POLICY_VIOLATION_CLOSE_CODE,
        );
    }
}
