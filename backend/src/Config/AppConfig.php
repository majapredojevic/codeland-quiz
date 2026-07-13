<?php

declare(strict_types=1);

namespace CodeLandQuiz\Config;

use CodeLandQuiz\Support\Environment;
use InvalidArgumentException;

final readonly class AppConfig
{
    private string $appName;

    private string $appEnv;

    private string $appUrl;

    private string $accessTokenCookieName;

    private string $refreshTokenCookieName;

    private string $cookiePath;

    private string $csrfTokenCookieName;

    private string $refreshTokenHashKey;

    private int $jwtExpirationMinutes;

    private int $refreshTokenExpirationDays;

    private int $csrfTokenExpirationMinutes;

    private int $loginAttemptLimit;

    private int $loginLockDurationMinutes;

    private bool $cookieSecure;

    private bool $cookieHttpOnly;

    private CookieSameSite $cookieSameSite;

    private int $maximumUploadSizeMb;

    /**
     * @var string[]
     */
    private array $allowedImageExtensions;

    private int $defaultQuizQuestionTimeLimitSeconds;

    private int $maximumNicknameLength;

    private int $defaultPageSize;

    private int $maximumPageSize;

    public function __construct(
        private readonly Environment $environment,
    ) {
        $this->appName = $this->environment->get('APP_NAME');
        $this->appEnv = $this->environment->get('APP_ENV');
        $this->appUrl = $this->environment->get('APP_URL');
        $this->accessTokenCookieName = $this->environment->get('ACCESS_TOKEN_COOKIE_NAME');
        $this->refreshTokenCookieName = $this->environment->get('REFRESH_TOKEN_COOKIE_NAME');
        $this->cookiePath = $this->environment->get('COOKIE_PATH');
        $this->csrfTokenCookieName = $this->environment->get('CSRF_TOKEN_COOKIE_NAME');
        $this->refreshTokenHashKey = $this->readRefreshTokenHashKey();
        $this->jwtExpirationMinutes = $this->environment->getInt('JWT_EXPIRATION_MINUTES');
        $this->refreshTokenExpirationDays = $this->environment->getInt('REFRESH_TOKEN_EXPIRATION_DAYS');
        $this->csrfTokenExpirationMinutes = $this->environment->getInt('CSRF_TOKEN_EXPIRATION_MINUTES');
        $this->loginAttemptLimit = $this->environment->getInt('LOGIN_ATTEMPT_LIMIT');
        $this->loginLockDurationMinutes = $this->environment->getInt('LOGIN_LOCK_DURATION_MINUTES');
        $this->cookieSecure = $this->environment->getBool('COOKIE_SECURE');
        $this->cookieHttpOnly = $this->environment->getBool('COOKIE_HTTP_ONLY');
        $this->cookieSameSite = CookieSameSite::from($this->environment->get('COOKIE_SAME_SITE'));
        $this->maximumUploadSizeMb = $this->environment->getInt('MAX_UPLOAD_SIZE_MB');
        $this->allowedImageExtensions = $this->parseAllowedImageExtensions(
            $this->environment->get('ALLOWED_IMAGE_EXTENSIONS'),
        );
        $this->defaultQuizQuestionTimeLimitSeconds = $this->environment->getInt(
            'DEFAULT_QUIZ_QUESTION_TIME_LIMIT_SECONDS',
        );
        $this->maximumNicknameLength = $this->environment->getInt('MAXIMUM_NICKNAME_LENGTH');
        $this->defaultPageSize = $this->environment->getInt('DEFAULT_PAGE_SIZE');
        $this->maximumPageSize = $this->environment->getInt('MAX_PAGE_SIZE');

        $this->ensurePositive($this->loginAttemptLimit, 'Login attempt limit');
        $this->ensurePositive($this->loginLockDurationMinutes, 'Login lock duration');
        $this->ensurePositive($this->jwtExpirationMinutes, 'JWT expiration');
        $this->ensurePositive($this->refreshTokenExpirationDays, 'Refresh token expiration');
        $this->ensurePositive($this->csrfTokenExpirationMinutes, 'CSRF token expiration');
        $this->ensurePositive($this->maximumUploadSizeMb, 'Maximum upload size');
        $this->ensurePositive($this->defaultQuizQuestionTimeLimitSeconds, 'Default question time limit');
        $this->ensurePositive($this->maximumNicknameLength, 'Maximum nickname length');
        $this->ensurePositive($this->defaultPageSize, 'Default page size');
        $this->ensurePositive($this->maximumPageSize, 'Maximum page size');

        if ($this->allowedImageExtensions === []) {
            throw new InvalidArgumentException('Allowed image extensions cannot be empty.');
        }

        if ($this->defaultPageSize > $this->maximumPageSize) {
            throw new InvalidArgumentException('Default page size cannot exceed maximum page size.');
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

    public function getRefreshTokenHashKey(): string
    {
        return $this->refreshTokenHashKey;
    }

    public function getCsrfTokenExpirationMinutes(): int
    {
        return $this->csrfTokenExpirationMinutes;
    }

    public function getMaximumUploadSizeMb(): int
    {
        return $this->maximumUploadSizeMb;
    }

    public function getAccessTokenCookieName(): string
    {
        return $this->accessTokenCookieName;
    }

    public function getRefreshTokenCookieName(): string
    {
        return $this->refreshTokenCookieName;
    }

    public function getCookiePath(): string
    {
        return $this->cookiePath;
    }

    public function getCsrfTokenCookieName(): string
    {
        return $this->csrfTokenCookieName;
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

    public function getDefaultPageSize(): int
    {
        return $this->defaultPageSize;
    }

    public function getMaximumPageSize(): int
    {
        return $this->maximumPageSize;
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

    public function getAppEnv(): string
    {
        return $this->appEnv;
    }

    public function getAppUrl(): string
    {
        return $this->appUrl;
    }

    /**
     * @return string[]
     */
    private function parseAllowedImageExtensions(string $extensions): array
    {
        return array_values(array_filter(
            array_map(
                static fn(string $extension): string => strtolower(trim($extension)),
                explode(',', $extensions),
            ),
            static fn(string $extension): bool => $extension !== '',
        ));
    }

    private function readRefreshTokenHashKey(): string
    {
        if (!$this->environment->has('REFRESH_TOKEN_HASH_KEY')) {
            throw new InvalidArgumentException(
                'Refresh token hash key must contain at least 32 characters.',
            );
        }

        $hashKey = $this->environment->get('REFRESH_TOKEN_HASH_KEY');

        if (strlen($hashKey) < 32) {
            throw new InvalidArgumentException(
                'Refresh token hash key must contain at least 32 characters.',
            );
        }

        return $hashKey;
    }

    private function ensurePositive(int $value, string $label): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException(sprintf('%s must be greater than zero.', $label));
        }
    }
}
