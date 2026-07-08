<?php

declare(strict_types=1);

namespace CodeLandQuiz\Support;

use JsonException;
use OpenSwoole\Http\Response;

final class JsonResponse
{
    /**
     * @param array<string, mixed> $payload
     *
     * @throws JsonException
     */
    public static function send(Response $response, array $payload, int $status = 200): void
    {
        $response->status($status);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
