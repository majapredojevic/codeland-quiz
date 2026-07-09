<?php

declare(strict_types=1);

namespace CodeLandQuiz\Http;

use JsonException;
use OpenSwoole\Http\Response;
use RuntimeException;

final class ResponseFactory
{
    /**
     * @param array<string, mixed> $payload
     */
    public function json(Response $response, array $payload, int $status = 200): void
    {
        $response->status($status);
        $response->header('Content-Type', 'application/json');
        $response->end($this->encode($payload));
    }

    public function error(Response $response, string $message, int $status): void
    {
        $this->json($response, [
            'error' => $message,
        ], $status);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('JSON response could not be encoded.', 0, $exception);
        }
    }
}