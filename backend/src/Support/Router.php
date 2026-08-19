<?php

declare(strict_types=1);

namespace CodeLandQuiz\Support;

use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\RequestIdGenerator;
use CodeLandQuiz\Observability\RuntimeLogger;
use CodeLandQuiz\Observability\RuntimeMetrics;
use CodeLandQuiz\Observability\PerformanceProfiler;
use OpenSwoole\Coroutine;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use RuntimeException;
use Throwable;

final class Router
{
    private const METHOD_ORDER = [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
    ];

    /**
     * @var array<string, array<int, array{
     *     path: string,
     *     pattern: string,
     *     parameterNames: string[],
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

    public function __construct(
        private readonly RuntimeLogger $logger,
        private readonly RuntimeMetrics $metrics,
        private readonly RequestIdGenerator $requestIdGenerator,
        private readonly ?PerformanceProfiler $profiler = null,
    ) {
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
        $startedAt = hrtime(true);
        $method = strtoupper(
            (string) ($request->server['request_method'] ?? 'GET'),
        );

        $path = parse_url(
            (string) ($request->server['request_uri'] ?? '/'),
            PHP_URL_PATH,
        ) ?: '/';

        $matchedRoute = $this->findRoute($method, $path);

        $context = new RequestContext(
            requestId: $this->requestIdGenerator->generate(),
            method: $method,
            route: $matchedRoute['route'] ?? $path,
        );
        $context->activate();
        $response->header('X-Request-ID', $context->getRequestId());

        try {
            if ($matchedRoute === null) {
                $this->sendRouteError($response, $path);

                return;
            }

            $context->setRouteParameters($matchedRoute['parameters']);

            $pipeline = $this->buildMiddlewarePipeline(
                $matchedRoute['handler'],
                $matchedRoute['middleware'],
            );

            $profileContext = $this->profileContextForRoute(
                $method,
                $matchedRoute['route'],
            );

            if ($this->profiler !== null && $profileContext !== null) {
                $this->profiler->inContext(
                    $profileContext,
                    fn () => $pipeline($request, $response, $context),
                );
            } else {
                $pipeline($request, $response, $context);
            }
        } catch (Throwable $throwable) {
            $this->logger->error('http.request_failed', [
                'requestId' => $context->getRequestId(),
                'route' => $context->getRoute(),
                'method' => $context->getMethod(),
                'coroutineId' => Coroutine::getCid(),
                'exception' => $throwable::class,
            ]);
            JsonResponse::send($response, [
                'error' => 'Internal server error.',
            ], 500);
        } finally {
            $this->metrics->recordHttpRequest();
            if (
                $this->profiler !== null
                && $matchedRoute !== null
                && !str_starts_with($matchedRoute['route'], '/internal/')
            ) {
                $this->profiler->recordDuration(
                    sprintf('http.%s %s', $method, $matchedRoute['route']),
                    $startedAt,
                );
            }
            $this->logger->info('http.request.completed', [
                'requestId' => $context->getRequestId(),
                'route' => $context->getRoute(),
                'method' => $context->getMethod(),
                'status' => $context->getResponseStatus(),
                'durationMs' => round(
                    (hrtime(true) - $startedAt) / 1_000_000,
                    3,
                ),
                'coroutineId' => Coroutine::getCid(),
            ]);
            $context->deactivate();
        }
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
        [$pattern, $parameterNames] = $this->compilePath($path);

        $this->routes[$method][] = [
            'path' => $path,
            'pattern' => $pattern,
            'parameterNames' => $parameterNames,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    /**
     * @return array{
     *     handler: callable(Request, Response, RequestContext): void,
     *     middleware: array<int, callable(
     *         Request,
     *         Response,
     *         RequestContext,
     *         callable
     *     ): void>,
     *     parameters: array<string, string>,
     *     route: string
     * }|null
     */
    private function findRoute(string $method, string $path): ?array
    {
        $routes = $this->routes[$method] ?? [];

        foreach ($routes as $route) {
            if ($route['path'] === $path) {
                return [
                    'handler' => $route['handler'],
                    'middleware' => $route['middleware'],
                    'parameters' => [],
                    'route' => $route['path'],
                ];
            }
        }

        foreach ($routes as $route) {
            $parameters = $this->matchRoute($route, $path);

            if ($parameters !== null) {
                return [
                    'handler' => $route['handler'],
                    'middleware' => $route['middleware'],
                    'parameters' => $parameters,
                    'route' => $route['path'],
                ];
            }
        }

        return null;
    }

    /**
     * @param array{
     *     path: string,
     *     pattern: string,
     *     parameterNames: string[],
     *     handler: callable(Request, Response, RequestContext): void,
     *     middleware: array<int, callable(
     *         Request,
     *         Response,
     *         RequestContext,
     *         callable
     *     ): void>
     * } $route
     *
     * @return array<string, string>|null
     */
    private function matchRoute(array $route, string $path): ?array
    {
        $matches = [];

        if (preg_match($route['pattern'], $path, $matches) !== 1) {
            return null;
        }

        $parameters = [];

        foreach ($route['parameterNames'] as $parameterName) {
            $value = $matches[$parameterName] ?? null;

            if (!is_string($value)) {
                return null;
            }

            $parameters[$parameterName] = rawurldecode($value);
        }

        return $parameters;
    }

    /**
     * @return array{0: string, 1: string[]}
     */
    private function compilePath(string $path): array
    {
        $parameterNames = [];
        $quotedPath = preg_quote($path, '#');

        $pattern = preg_replace_callback(
            '/\\\\\{([A-Za-z_][A-Za-z0-9_]*)\\\\\}/',
            static function (array $matches) use (&$parameterNames): string {
                $parameterName = $matches[1];

                $parameterNames[] = $parameterName;

                return sprintf(
                    '(?P<%s>[^/]+)',
                    $parameterName,
                );
            },
            $quotedPath,
        );

        if ($pattern === null) {
            throw new RuntimeException('Route pattern could not be compiled.');
        }

        return [
            sprintf('#^%s$#', $pattern),
            $parameterNames,
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
        $allowedMethods = $this->allowedMethodsForPath($path);

        if ($allowedMethods !== []) {
            $response->header('Allow', implode(', ', $allowedMethods));
            JsonResponse::send($response, [
                'error' => 'Method not allowed.',
            ], 405);

            return;
        }

        JsonResponse::send($response, [
            'error' => 'Not found.',
        ], 404);
    }

    /**
     * @return string[]
     */
    private function allowedMethodsForPath(string $path): array
    {
        $allowedMethods = [];

        foreach (self::METHOD_ORDER as $method) {
            foreach ($this->routes[$method] ?? [] as $route) {
                if (
                    $route['path'] === $path
                    || preg_match($route['pattern'], $path) === 1
                ) {
                    $allowedMethods[] = $method;

                    break;
                }
            }
        }

        return $allowedMethods;
    }

    private function profileContextForRoute(
        string $method,
        string $route,
    ): ?string {
        return match (sprintf('%s %s', $method, $route)) {
            'GET /api/game/session/{gamePin}' => 'preview',
            'POST /api/sessions/{id}/questions/current/close' =>
                'question_close',
            default => null,
        };
    }
}
