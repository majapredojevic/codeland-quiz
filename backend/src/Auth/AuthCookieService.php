<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use CodeLandQuiz\Config\AppConfig;
use OpenSwoole\Http\Response;
use RuntimeException;

final readonly class AuthCookieService
{
    private const SECONDS_PER_MINUTE = 60;

    private const SECONDS_PER_DAY = 86400;

    public function __construct(
        private AppConfig $config,
    ) {}

    public function setAuthenticationCookies(
        Response $response,
        string $accessToken,
        string $refreshToken,
    ): void {
        $now = time();

        $this->setCookie(
            response: $response,
            name: $this->config->getAccessTokenCookieName(),
            value: $accessToken,
            expiresAt: $now
                + ($this->config->getJwtExpirationMinutes() * self::SECONDS_PER_MINUTE),
        );

        $this->setCookie(
            response: $response,
            name: $this->config->getRefreshTokenCookieName(),
            value: $refreshToken,
            expiresAt: $now
                + ($this->config->getRefreshTokenExpirationDays() * self::SECONDS_PER_DAY),
        );
    }

    public function setCsrfCookie(
        Response $response,
        string $csrfToken,
    ): void {
        $success = $response->cookie(
            $this->config->getCsrfTokenCookieName(),
            $csrfToken,
            time() + (
                $this->config->getCsrfTokenExpirationMinutes()
                * self::SECONDS_PER_MINUTE
            ),
            $this->config->getCookiePath(),
            '',
            $this->config->isCookieSecure(),
            false,
            $this->config->getCookieSameSite()->value,
        );

        if ($success === false) {
            throw new RuntimeException('CSRF cookie could not be set.');
        }
    }

    public function clearAuthenticationCookies(Response $response): void
    {
        $expiresAt = time() - 3600;

        $this->clearCookie(
            $response,
            $this->config->getAccessTokenCookieName(),
            true,
            $expiresAt,
        );

        $this->clearCookie(
            $response,
            $this->config->getRefreshTokenCookieName(),
            true,
            $expiresAt,
        );

        $this->clearCookie(
            $response,
            $this->config->getCsrfTokenCookieName(),
            false,
            $expiresAt,
        );
    }

    private function setCookie(
        Response $response,
        string $name,
        string $value,
        int $expiresAt,
    ): void {
        $success = $response->cookie(
            $name,
            $value,
            $expiresAt,
            $this->config->getCookiePath(),
            '',
            $this->config->isCookieSecure(),
            $this->config->isCookieHttpOnly(),
            $this->config->getCookieSameSite()->value,
        );

        if ($success === false) {
            throw new RuntimeException(sprintf('Cookie "%s" could not be set.', $name));
        }
    }

    private function clearCookie(
        Response $response,
        string $name,
        bool $httpOnly,
        int $expiresAt,
    ): void {
        $success = $response->cookie(
            $name,
            '',
            $expiresAt,
            $this->config->getCookiePath(),
            '',
            $this->config->isCookieSecure(),
            $httpOnly,
            $this->config->getCookieSameSite()->value,
        );

        if ($success === false) {
            throw new RuntimeException(
                sprintf('Cookie "%s" could not be cleared.', $name),
            );
        }
    }
}
