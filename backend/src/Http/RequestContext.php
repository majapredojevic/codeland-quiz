<?php

declare(strict_types=1);

namespace CodeLandQuiz\Http;

use CodeLandQuiz\DTO\AccessTokenPayload;
use RuntimeException;

final class RequestContext
{
    private ?AccessTokenPayload $authenticatedUser = null;

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
}
