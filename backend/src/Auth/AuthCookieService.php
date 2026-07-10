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
    ) {
    }

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
}