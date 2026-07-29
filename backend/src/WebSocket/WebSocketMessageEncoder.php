<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

final class WebSocketMessageEncoder
{
    /**
     * @param array<string, mixed> $payload
     */
    public function encode(string $type, array $payload = []): string
    {
        return json_encode(
            [
                'type' => $type,
                'payload' => $payload,
            ],
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES,
        );
    }
}
