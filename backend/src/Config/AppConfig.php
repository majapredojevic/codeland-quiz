<?php

declare(strict_types=1);

namespace CodeLandQuiz\Config;

use InvalidArgumentException;

final readonly class AppConfig
{
    /**
     * @param string[] $allowedImageExtensions
     */
    public function __construct(
        private int $loginAttemptLimit = 5,
        private int $loginLockDurationMinutes = 15,
        private int $jwtExpirationMinutes = 60,
        private int $refreshTokenExpirationDays = 7,
        private int $csrfTokenExpirationMinutes = 120,
        private int $maximumUploadSizeMb = 5,
        private array $allowedImageExtensions = ['jpg', 'jpeg', 'png', 'webp'],
        private int $defaultQuizQuestionTimeLimitSeconds = 30,
        private int $maximumNicknameLength = 100,
        private bool $cookieSecure = true,
        private bool $cookieHttpOnly = true,
        private CookieSameSite $cookieSameSite = CookieSameSite::STRICT,
        private string $appName = 'CodeLand Quiz',
        private string $appUrl = 'https://localhost',
    ) {
        $this->ensurePositive($this->loginAttemptLimit, 'Login attempt limit');
        $this->ensurePositive($this->loginLockDurationMinutes, 'Login lock duration');
        $this->ensurePositive($this->jwtExpirationMinutes, 'JWT expiration');
        $this->ensurePositive($this->refreshTokenExpirationDays, 'Refresh token expiration');
        $this->ensurePositive($this->csrfTokenExpirationMinutes, 'CSRF token expiration');
        $this->ensurePositive($this->maximumUploadSizeMb, 'Maximum upload size');
        $this->ensurePositive($this->defaultQuizQuestionTimeLimitSeconds, 'Default question time limit');
        $this->ensurePositive($this->maximumNicknameLength, 'Maximum nickname length');

        if ($this->allowedImageExtensions === []) {
            throw new InvalidArgumentException('Allowed image extensions cannot be empty.');
        }
    }

    public function getLoginAttemptLimit(): int
    {
        return $this->loginAttemptLimit;
    }

    public function getLoginLockDurationMinutes(): int
    {
        return $this->loginLockDurationMinutes;
    }

    public function getJwtExpirationMinutes(): int
    {
        return $this->jwtExpirationMinutes;
    }

    public function getRefreshTokenExpirationDays(): int
    {
        return $this->refreshTokenExpirationDays;
    }

    public function getCsrfTokenExpirationMinutes(): int
    {
        return $this->csrfTokenExpirationMinutes;
    }

    public function getMaximumUploadSizeMb(): int
    {
        return $this->maximumUploadSizeMb;
    }

    /**
     * @return string[]
     */
    public function getAllowedImageExtensions(): array
    {
        return $this->allowedImageExtensions;
    }

    public function getDefaultQuizQuestionTimeLimitSeconds(): int
    {
        return $this->defaultQuizQuestionTimeLimitSeconds;
    }

    public function getMaximumNicknameLength(): int
    {
        return $this->maximumNicknameLength;
    }

    public function isCookieSecure(): bool
    {
        return $this->cookieSecure;
    }

    public function isCookieHttpOnly(): bool
    {
        return $this->cookieHttpOnly;
    }

    public function getCookieSameSite(): CookieSameSite
    {
        return $this->cookieSameSite;
    }

    public function getAppName(): string
    {
        return $this->appName;
    }

    public function getAppUrl(): string
    {
        return $this->appUrl;
    }

    private function ensurePositive(int $value, string $label): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException(sprintf('%s must be greater than zero.', $label));
        }
    }
}
