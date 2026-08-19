<?php

declare(strict_types=1);

namespace CodeLandQuiz\Http;

use CodeLandQuiz\Observability\PerformanceProfiler;
use JsonException;
use OpenSwoole\Http\Response;
use RuntimeException;

final class ResponseFactory
{
    public function __construct(
        private readonly ?PerformanceProfiler $profiler = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function json(
        Response $response,
        array $payload,
        int $status = 200,
        ?string $serializationProfile = null,
    ): void {
        RequestContext::recordCurrentResponseStatus($status);
        $response->status($status);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        $encodedPayload = $serializationProfile !== null
            && $this->profiler !== null
                ? $this->profiler->measure(
                    $serializationProfile,
                    fn (): string => $this->encode($payload),
                )
                : $this->encode($payload);
        $response->end($encodedPayload);
    }

    public function error(Response $response, string $message, int $status): void
    {
        $this->json($response, [
            'error' => $message,
        ], $status);
    }

    public function noContent(Response $response): void
    {
        RequestContext::recordCurrentResponseStatus(204);
        $response->status(204);
        $response->end();
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
