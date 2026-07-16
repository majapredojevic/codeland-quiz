<?php

declare(strict_types=1);

namespace CodeLandQuiz\Http;

use CodeLandQuiz\DTO\AccessTokenPayload;
use CodeLandQuiz\DTO\CurrentUserDTO;
use InvalidArgumentException;
use RuntimeException;

final class RequestContext
{
    private ?AccessTokenPayload $authenticatedUser = null;

    private ?CurrentUserDTO $currentUser = null;

    /**
     * @var array<string, string>
     */
    private array $routeParameters = [];

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
}
