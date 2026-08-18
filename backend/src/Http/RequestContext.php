<?php

declare(strict_types=1);

namespace CodeLandQuiz\Http;

use CodeLandQuiz\DTO\AccessTokenPayload;
use CodeLandQuiz\DTO\CurrentUserDTO;
use InvalidArgumentException;
use OpenSwoole\Coroutine;
use RuntimeException;

final class RequestContext
{
    private const COROUTINE_CONTEXT_KEY = 'codeland_quiz.http.request_context';

    private static ?self $fallbackCurrentContext = null;

    private ?AccessTokenPayload $authenticatedUser = null;

    private ?CurrentUserDTO $currentUser = null;

    /**
     * @var array<string, string>
     */
    private array $routeParameters = [];

    private int $responseStatus = 200;

    public function __construct(
        private readonly string $requestId,
        private readonly string $method,
        private readonly string $route,
    ) {
    }

    public function activate(): void
    {
        if (Coroutine::getCid() >= 0) {
            $coroutineContext = Coroutine::getContext();

            if ($coroutineContext !== null) {
                $coroutineContext[self::COROUTINE_CONTEXT_KEY] = $this;

                return;
            }
        }

        self::$fallbackCurrentContext = $this;
    }

    public function deactivate(): void
    {
        if (Coroutine::getCid() >= 0) {
            $coroutineContext = Coroutine::getContext();

            if (
                $coroutineContext !== null
                && ($coroutineContext[self::COROUTINE_CONTEXT_KEY] ?? null)
                    === $this
            ) {
                unset($coroutineContext[self::COROUTINE_CONTEXT_KEY]);
            }

            return;
        }

        if (self::$fallbackCurrentContext === $this) {
            self::$fallbackCurrentContext = null;
        }
    }

    public static function recordCurrentResponseStatus(int $status): void
    {
        $context = self::current();

        if ($context !== null) {
            $context->responseStatus = $status;
        }
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getRoute(): string
    {
        return $this->route;
    }

    public function getResponseStatus(): int
    {
        return $this->responseStatus;
    }

    public function setAuthenticatedUser(AccessTokenPayload $payload): void
    {
        $this->authenticatedUser = $payload;
    }

    public function getAuthenticatedUser(): AccessTokenPayload
    {
        if ($this->authenticatedUser === null) {
            throw new RuntimeException('Authenticated user is not set.');
        }

        return $this->authenticatedUser;
    }

    public function hasAuthenticatedUser(): bool
    {
        return $this->authenticatedUser !== null;
    }

    public function setCurrentUser(CurrentUserDTO $currentUser): void
    {
        $this->currentUser = $currentUser;
    }

    public function getCurrentUser(): CurrentUserDTO
    {
        if ($this->currentUser === null) {
            throw new RuntimeException('Current user is not set.');
        }

        return $this->currentUser;
    }

    public function hasCurrentUser(): bool
    {
        return $this->currentUser !== null;
    }

    /**
     * @param array<string, string> $parameters
     */
    public function setRouteParameters(array $parameters): void
    {
        $this->routeParameters = $parameters;
    }

    public function getRouteParameter(string $name): string
    {
        $value = $this->routeParameters[$name] ?? null;

        if ($value === null || $value === '') {
            throw new InvalidArgumentException(
                sprintf('Route parameter "%s" is missing.', $name),
            );
        }

        return $value;
    }

    public function getRouteInt(string $name): int
    {
        $value = $this->getRouteParameter($name);

        if (
            filter_var($value, FILTER_VALIDATE_INT) === false
            || (int) $value < 1
        ) {
            throw new InvalidArgumentException(
                sprintf('Route parameter "%s" must be a positive integer.', $name),
            );
        }

        return (int) $value;
    }

    private static function current(): ?self
    {
        if (Coroutine::getCid() >= 0) {
            $coroutineContext = Coroutine::getContext();
            $context = $coroutineContext[self::COROUTINE_CONTEXT_KEY] ?? null;

            return $context instanceof self ? $context : null;
        }

        return self::$fallbackCurrentContext;
    }
}
