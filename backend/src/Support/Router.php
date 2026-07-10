<?php

declare(strict_types=1);

namespace CodeLandQuiz\Support;

use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

final class Router
{
    /**
     * @var array<string, array<string, callable(Request, Response): void>>
     */
    private array $routes = [];

    /**
     * @param callable(Request, Response): void $handler
     */
    public function get(string $path, callable $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    /**
     * @param callable(Request, Response): void $handler
     */
    public function post(string $path, callable $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    /**
     * @param callable(Request, Response): void $handler
     */
    public function put(string $path, callable $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    /**
     * @param callable(Request, Response): void $handler
     */
    public function patch(string $path, callable $handler): void
    {
        $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * @param callable(Request, Response): void $handler
     */
    public function delete(string $path, callable $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    public function dispatch(Request $request, Response $response): void
    {
        $method = strtoupper(
            (string) ($request->server['request_method'] ?? 'GET'),
        );

        $path = parse_url(
            (string) ($request->server['request_uri'] ?? '/'),
            PHP_URL_PATH,
        ) ?: '/';

        $handler = $this->routes[$method][$path] ?? null;

        if ($handler !== null) {
            $handler($request, $response);

            return;
        }

        if ($this->pathExists($path)) {
            JsonResponse::send($response, [
                'error' => 'Method not allowed',
            ], 405);

            return;
        }

        JsonResponse::send($response, [
            'error' => 'Not found',
        ], 404);
    }

    /**
     * @param callable(Request, Response): void $handler
     */
    private function addRoute(
        string $method,
        string $path,
        callable $handler,
    ): void {
        $this->routes[$method][$path] = $handler;
    }

    private function pathExists(string $path): bool
    {
        foreach ($this->routes as $routesByMethod) {
            if (isset($routesByMethod[$path])) {
                return true;
            }
        }

        return false;
    }
}
