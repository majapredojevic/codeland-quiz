<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

final readonly class WebSocketRoutePolicy
{
    public const GAME = 'game';
    public const ECHO = 'echo';

    public function __construct(
        private bool $echoEnabled,
    ) {
    }

    public function routeForUri(string $requestUri): ?string
    {
        $path = parse_url($requestUri, PHP_URL_PATH);

        return match ($path) {
            '/ws/game' => self::GAME,
            '/ws/echo' => $this->echoEnabled ? self::ECHO : null,
            default => null,
        };
    }
}
