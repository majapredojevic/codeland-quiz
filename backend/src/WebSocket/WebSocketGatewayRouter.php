<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use CodeLandQuiz\Support\ClientAddress;
use CodeLandQuiz\Observability\RuntimeLogger;
use CodeLandQuiz\Observability\PerformanceProfiler;
use CodeLandQuiz\WebSocket\Exception\WebSocketRateLimitExceededException;
use OpenSwoole\Http\Request;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;
use Throwable;

final class WebSocketGatewayRouter
{
    private const POLICY_VIOLATION_CLOSE_CODE = 1008;

    /**
     * @var array<int, WebSocketGateway>
     */
    private array $gatewaysByFileDescriptor = [];

    public function __construct(
        private readonly ParticipantWebSocketGateway $participantGateway,
        private readonly EchoGateway $echoGateway,
        private readonly WebSocketMessageEncoder $messageEncoder,
        private readonly WebSocketOriginPolicy $originPolicy,
        private readonly WebSocketFramePolicy $framePolicy,
        private readonly WebSocketConnectionLimiter $connectionLimiter,
        private readonly WebSocketAbuseLimiter $abuseLimiter,
        private readonly ClientAddress $clientAddress,
        private readonly WebSocketRoutePolicy $routePolicy,
        private readonly RuntimeLogger $logger,
        private readonly ?PerformanceProfiler $profiler = null,
    ) {
    }

    public function allowsHandshake(Request $request): bool
    {
        if ($this->profiler === null) {
            return $this->gatewayForRequest($request) !== null
                && $this->originPolicy->allows(
                    $request->header['origin'] ?? null,
                );
        }

        $routeAllowed = $this->profiler->measure(
            'ws_handshake.route_validation',
            fn (): bool => $this->gatewayForRequest($request) !== null,
        );

        return $routeAllowed && $this->profiler->measure(
            'ws_handshake.origin_validation',
            fn (): bool => $this->originPolicy->allows(
                $request->header['origin'] ?? null,
            ),
        );
    }

    public function open(Server $server, Request $request): void
    {
        if ($this->profiler === null) {
            $this->openConnection($server, $request);

            return;
        }

        $this->profiler->measure(
            'ws_open.total',
            fn () => $this->openConnection($server, $request),
        );
    }

    private function openConnection(Server $server, Request $request): void
    {
        $fileDescriptor = (int) $request->fd;

        if (!$server->isEstablished($fileDescriptor)) {
            return;
        }

        $gateway = $this->gatewayForRequest($request);

        if ($gateway === null) {
            $this->rejectUnknownPath($server, $fileDescriptor);

            return;
        }

        $clientIdentifier = $this->clientAddress->identifier(
            $request->server['remote_addr'] ?? null,
            $request->header['x-real-ip'] ?? null,
        );

        try {
            $registerConnection = function () use (
                $fileDescriptor,
                $clientIdentifier,
                $gateway,
            ): void {
                $this->connectionLimiter->register(
                    fileDescriptor: $fileDescriptor,
                    clientIdentifier: $clientIdentifier,
                    pendingAuthentication:
                        $gateway === $this->participantGateway,
                );
                $this->abuseLimiter->registerConnection(
                    $fileDescriptor,
                    $clientIdentifier,
                );
            };

            if ($this->profiler !== null) {
                $this->profiler->measure(
                    'ws_open.connection_limit_registration',
                    $registerConnection,
                );
            } else {
                $registerConnection();
            }
        } catch (WebSocketRateLimitExceededException) {
            $this->logger->warning('websocket.connection_limit_rejected', [
                'fd' => $fileDescriptor,
                'reason' => 'connection_limit',
            ]);
            $this->pushError(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'CONNECTION_LIMIT_REACHED',
                message: 'WebSocket connection is temporarily unavailable.',
            );
            $this->disconnect($server, $fileDescriptor);

            return;
        }

        $this->gatewaysByFileDescriptor[$fileDescriptor] = $gateway;

        try {
            if ($this->profiler !== null) {
                $this->profiler->measure(
                    'ws_open.gateway_bookkeeping',
                    fn () => $gateway->open($server, $request),
                );
            } else {
                $gateway->open($server, $request);
            }
        } catch (Throwable $throwable) {
            unset($this->gatewaysByFileDescriptor[$fileDescriptor]);
            $this->connectionLimiter->remove($fileDescriptor);
            $this->abuseLimiter->removeConnection($fileDescriptor);
            $this->logger->error('websocket.open_failed', [
                'fd' => $fileDescriptor,
                'exception' => $throwable::class,
            ]);
            $this->rejectWithInternalError($server, $fileDescriptor);
        }
    }

    public function message(Server $server, Frame $frame): void
    {
        if (!$this->framePolicy->allows($frame->data)) {
            $this->pushError(
                server: $server,
                fileDescriptor: $frame->fd,
                code: 'MESSAGE_TOO_LARGE',
                message: 'WebSocket message is too large.',
            );
            $this->disconnect($server, $frame->fd);

            return;
        }

        $gateway = $this->gatewaysByFileDescriptor[$frame->fd] ?? null;

        if ($gateway === null) {
            $this->rejectUnknownPath($server, $frame->fd);

            return;
        }

        try {
            $gateway->message($server, $frame);
        } catch (Throwable $throwable) {
            $this->logger->error('websocket.message_failed', [
                'fd' => $frame->fd,
                'exception' => $throwable::class,
            ]);
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
            $this->logger->error('websocket.close_failed', [
                'fd' => $fileDescriptor,
                'exception' => $throwable::class,
            ]);
        } finally {
            $this->connectionLimiter->remove($fileDescriptor);
            $this->abuseLimiter->removeConnection($fileDescriptor);
        }
    }

    public function heartbeatSweep(
        Server $server,
        int $monotonicNanoseconds,
        int $staleTimeoutSeconds,
    ): int {
        return $this->participantGateway->heartbeatSweep(
            server: $server,
            monotonicNanoseconds: $monotonicNanoseconds,
            staleTimeoutSeconds: $staleTimeoutSeconds,
        );
    }

    private function gatewayForRequest(Request $request): ?WebSocketGateway
    {
        $route = $this->routePolicy->routeForUri(
            (string) ($request->server['request_uri'] ?? ''),
        );

        return match ($route) {
            WebSocketRoutePolicy::GAME => $this->participantGateway,
            WebSocketRoutePolicy::ECHO => $this->echoGateway,
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
