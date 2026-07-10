<?php

declare(strict_types=1);

namespace CodeLandQuiz\Support;

use CodeLandQuiz\Http\RequestContext;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

final class Router
{
    /**
     * @var array<string, array<string, array{
     *     handler: callable(Request, Response, RequestContext): void,
     *     middleware: array<int, callable(
     *         Request,
     *         Response,
     *         RequestContext,
     *         callable
     *     ): void>
     * }>>
     */
    private array $routes = [];

    /**
     * @param callable(Request, Response, RequestContext): void $handler
     * @param array<int, callable(
     *     Request,
     *     Response,
     *     RequestContext,
     *     callable
     * ): void> $middleware
     */
    public function get(
        string $path,
        callable $handler,
        array $middleware = [],
    ): void {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    /**
     * @param callable(Request, Response, RequestContext): void $handler
     * @param array<int, callable(
     *     Request,
     *     Response,
     *     RequestContext,
     *     callable
     * ): void> $middleware
     */
    public function post(
        string $path,
        callable $handler,
        array $middleware = [],
    ): void {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    /**
     * @param callable(Request, Response, RequestContext): void $handler
     * @param array<int, callable(
     *     Request,
     *     Response,
     *     RequestContext,
     *     callable
     * ): void> $middleware
     */
    public function put(
        string $path,
        callable $handler,
        array $middleware = [],
    ): void {
        $this->addRoute('PUT', $path, $handler, $middleware);
    }

    /**
     * @param callable(Request, Response, RequestContext): void $handler
     * @param array<int, callable(
     *     Request,
     *     Response,
     *     RequestContext,
     *     callable
     * ): void> $middleware
     */
    public function patch(
        string $path,
        callable $handler,
        array $middleware = [],
    ): void {
        $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    /**
     * @param callable(Request, Response, RequestContext): void $handler
     * @param array<int, callable(
     *     Request,
     *     Response,
     *     RequestContext,
     *     callable
     * ): void> $middleware
     */
    public function delete(
        string $path,
        callable $handler,
        array $middleware = [],
    ): void {
        $this->addRoute('DELETE', $path, $handler, $middleware);
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

        $route = $this->routes[$method][$path] ?? null;

        if ($route === null) {
            $this->sendRouteError($response, $path);

            return;
        }

        // A new context is created for every request.
        // This is especially important because OpenSwoole is long-running.
        $context = new RequestContext();

        $pipeline = $this->buildMiddlewarePipeline(
            $route['handler'],
            $route['middleware'],
        );

        $pipeline($request, $response, $context);
    }

    /**
     * @param callable(Request, Response, RequestContext): void $handler
     * @param array<int, callable(
     *     Request,
     *     Response,
     *     RequestContext,
     *     callable
     * ): void> $middleware
     */
    private function addRoute(
        string $method,
        string $path,
        callable $handler,
        array $middleware,
    ): void {
        $this->routes[$method][$path] = [
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /**
     * @param callable(Request, Response, RequestContext): void $handler
     * @param array<int, callable(
     *     Request,
     *     Response,
     *     RequestContext,
     *     callable
     * ): void> $middleware
     *
     * @return callable(Request, Response, RequestContext): void
     */
    private function buildMiddlewarePipeline(
        callable $handler,
        array $middleware,
    ): callable {
        $pipeline = $handler;

        foreach (array_reverse($middleware) as $currentMiddleware) {
            $next = $pipeline;

            $pipeline = static function (
                Request $request,
                Response $response,
                RequestContext $context,
            ) use ($currentMiddleware, $next): void {
                $currentMiddleware(
                    $request,
                    $response,
                    $context,
                    $next,
                );
            };
        }

        return $pipeline;
    }

    private function sendRouteError(Response $response, string $path): void
    {
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
