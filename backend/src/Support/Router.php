<?php

declare(strict_types=1);

namespace CodeLandQuiz\Support;

use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

final class Router
{
    /**
     * @var array<string, callable(Request, Response): void>
     */
    private array $getRoutes = [];

    /**
     * @param callable(Request, Response): void $handler
     */
    public function get(string $path, callable $handler): void
    {
        $this->getRoutes[$path] = $handler;
    }

    public function dispatch(Request $request, Response $response): void
    {
        $method = strtoupper((string) ($request->server['request_method'] ?? 'GET'));
        $path = parse_url((string) ($request->server['request_uri'] ?? '/'), PHP_URL_PATH) ?: '/';

        if ($method !== 'GET') {
            JsonResponse::send($response, [
                'error' => 'Method not allowed',
            ], 405);

            return;
        }

        $handler = $this->getRoutes[$path] ?? null;

        if ($handler === null) {
            JsonResponse::send($response, [
                'error' => 'Not found',
            ], 404);

            return;
        }

        $handler($request, $response);
    }
}
