<?php

declare(strict_types=1);

namespace CodeLandQuiz\Config;

use CodeLandQuiz\Support\Environment;
use InvalidArgumentException;

final readonly class AppConfig
{
    private const SUPPORTED_IMAGE_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'webp',
    ];

    private const BYTES_PER_MEGABYTE = 1024 * 1024;

    private const MULTIPART_OVERHEAD_BYTES = 1024 * 1024;

    private string $appName;

    private string $appEnv;

    private string $appUrl;

    private string $accessTokenCookieName;

    private string $refreshTokenCookieName;

    private string $cookiePath;

    private string $csrfTokenCookieName;

    private string $refreshTokenHashKey;

    private string $participantTokenSecret;

    private int $jwtExpirationMinutes;

    private int $participantTokenTtlSeconds;

    private int $refreshTokenExpirationDays;

    private int $csrfTokenExpirationMinutes;

    private int $loginAttemptLimit;

    private int $loginLockDurationMinutes;

    private int $loginIpAttemptLimit;

    /**
     * @var string[]
     */
    private array $webSocketAllowedOrigins;

    private int $webSocketGameplayMaximumFrameBytes;

    private int $webSocketAuthenticationAttemptLimit;

    private int $webSocketAuthenticationIpAttemptLimit;

    private int $webSocketAuthenticationIpWindowSeconds;

    private int $webSocketAnswerAttemptLimit;

    private int $webSocketAnswerAttemptWindowSeconds;

    private int $webSocketConnectionLimit;

    private int $webSocketPendingConnectionLimit;

    private int $webSocketConnectionPerIpLimit;

    private bool $cookieSecure;

    private bool $cookieHttpOnly;

    private CookieSameSite $cookieSameSite;

    private int $maximumUploadSizeMb;

    private string $questionImageStoragePath;

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
        $this->participantTokenSecret = $this->readParticipantTokenSecret();
        $this->jwtExpirationMinutes = $this->environment->getInt('JWT_EXPIRATION_MINUTES');
        $this->participantTokenTtlSeconds = $this->environment->getInt('PARTICIPANT_TOKEN_TTL_SECONDS');
        $this->refreshTokenExpirationDays = $this->environment->getInt('REFRESH_TOKEN_EXPIRATION_DAYS');
        $this->csrfTokenExpirationMinutes = $this->environment->getInt('CSRF_TOKEN_EXPIRATION_MINUTES');
        $this->loginAttemptLimit = $this->environment->getInt('LOGIN_ATTEMPT_LIMIT');
        $this->loginLockDurationMinutes = $this->environment->getInt('LOGIN_LOCK_DURATION_MINUTES');
        $this->loginIpAttemptLimit = $this->environment->getInt('LOGIN_IP_ATTEMPT_LIMIT');
        $this->webSocketAllowedOrigins = $this->parseList(
            $this->environment->get('WS_ALLOWED_ORIGINS'),
        );
        $this->webSocketGameplayMaximumFrameBytes = $this->environment->getInt(
            'WS_GAMEPLAY_MAX_FRAME_BYTES',
        );
        $this->webSocketAuthenticationAttemptLimit = $this->environment->getInt(
            'WS_AUTH_ATTEMPT_LIMIT',
        );
        $this->webSocketAuthenticationIpAttemptLimit = $this->environment->getInt(
            'WS_AUTH_IP_ATTEMPT_LIMIT',
        );
        $this->webSocketAuthenticationIpWindowSeconds = $this->environment->getInt(
            'WS_AUTH_IP_WINDOW_SECONDS',
        );
        $this->webSocketAnswerAttemptLimit = $this->environment->getInt(
            'WS_ANSWER_ATTEMPT_LIMIT',
        );
        $this->webSocketAnswerAttemptWindowSeconds = $this->environment->getInt(
            'WS_ANSWER_ATTEMPT_WINDOW_SECONDS',
        );
        $this->webSocketConnectionLimit = $this->environment->getInt(
            'WS_CONNECTION_LIMIT',
        );
        $this->webSocketPendingConnectionLimit = $this->environment->getInt(
            'WS_PENDING_CONNECTION_LIMIT',
        );
        $this->webSocketConnectionPerIpLimit = $this->environment->getInt(
            'WS_CONNECTION_PER_IP_LIMIT',
        );
        $this->cookieSecure = $this->environment->getBool('COOKIE_SECURE');
        $this->cookieHttpOnly = $this->environment->getBool('COOKIE_HTTP_ONLY');
        $this->cookieSameSite = CookieSameSite::from($this->environment->get('COOKIE_SAME_SITE'));
        $this->maximumUploadSizeMb = $this->environment->getInt('MAX_UPLOAD_SIZE_MB');
        $this->questionImageStoragePath = $this->resolveQuestionImageStoragePath(
            $this->environment->has('QUESTION_IMAGE_STORAGE_PATH')
                ? $this->environment->get('QUESTION_IMAGE_STORAGE_PATH')
                : 'storage/question-images',
        );
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
        $this->ensurePositive($this->loginIpAttemptLimit, 'Login IP attempt limit');
        $this->ensurePositive($this->webSocketGameplayMaximumFrameBytes, 'WebSocket gameplay frame limit');
        $this->ensurePositive($this->webSocketAuthenticationAttemptLimit, 'WebSocket authentication attempt limit');
        $this->ensurePositive($this->webSocketAuthenticationIpAttemptLimit, 'WebSocket authentication IP attempt limit');
        $this->ensurePositive($this->webSocketAuthenticationIpWindowSeconds, 'WebSocket authentication IP window');
        $this->ensurePositive($this->webSocketAnswerAttemptLimit, 'WebSocket answer attempt limit');
        $this->ensurePositive($this->webSocketAnswerAttemptWindowSeconds, 'WebSocket answer attempt window');
        $this->ensurePositive($this->webSocketConnectionLimit, 'WebSocket connection limit');
        $this->ensurePositive($this->webSocketPendingConnectionLimit, 'WebSocket pending connection limit');
        $this->ensurePositive($this->webSocketConnectionPerIpLimit, 'WebSocket connection per-IP limit');
        $this->ensurePositive($this->jwtExpirationMinutes, 'JWT expiration');
        $this->ensurePositive($this->participantTokenTtlSeconds, 'Participant token TTL');
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

        if ($this->webSocketAllowedOrigins === []) {
            throw new InvalidArgumentException(
                'WebSocket allowed origins cannot be empty.',
            );
        }

        if (in_array('*', $this->webSocketAllowedOrigins, true)) {
            throw new InvalidArgumentException(
                'WebSocket allowed origins cannot contain a wildcard.',
            );
        }

        if ($this->webSocketPendingConnectionLimit > $this->webSocketConnectionLimit) {
            throw new InvalidArgumentException(
                'WebSocket pending connection limit cannot exceed the global limit.',
            );
        }

        if ($this->webSocketConnectionPerIpLimit > $this->webSocketConnectionLimit) {
            throw new InvalidArgumentException(
                'WebSocket per-IP connection limit cannot exceed the global limit.',
            );
        }

        $unsupportedImageExtensions = array_diff(
            $this->allowedImageExtensions,
            self::SUPPORTED_IMAGE_EXTENSIONS,
        );

        if ($unsupportedImageExtensions !== []) {
            throw new InvalidArgumentException(
                'Allowed image extensions may only contain jpg, jpeg, png and webp.',
            );
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

    public function getLoginIpAttemptLimit(): int
    {
        return $this->loginIpAttemptLimit;
    }

    /**
     * @return string[]
     */
    public function getWebSocketAllowedOrigins(): array
    {
        return $this->webSocketAllowedOrigins;
    }

    public function getWebSocketGameplayMaximumFrameBytes(): int
    {
        return $this->webSocketGameplayMaximumFrameBytes;
    }

    public function getWebSocketAuthenticationAttemptLimit(): int
    {
        return $this->webSocketAuthenticationAttemptLimit;
    }

    public function getWebSocketAuthenticationIpAttemptLimit(): int
    {
        return $this->webSocketAuthenticationIpAttemptLimit;
    }

    public function getWebSocketAuthenticationIpWindowSeconds(): int
    {
        return $this->webSocketAuthenticationIpWindowSeconds;
    }

    public function getWebSocketAnswerAttemptLimit(): int
    {
        return $this->webSocketAnswerAttemptLimit;
    }

    public function getWebSocketAnswerAttemptWindowSeconds(): int
    {
        return $this->webSocketAnswerAttemptWindowSeconds;
    }

    public function getWebSocketConnectionLimit(): int
    {
        return $this->webSocketConnectionLimit;
    }

    public function getWebSocketPendingConnectionLimit(): int
    {
        return $this->webSocketPendingConnectionLimit;
    }

    public function getWebSocketConnectionPerIpLimit(): int
    {
        return $this->webSocketConnectionPerIpLimit;
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

    public function getParticipantTokenSecret(): string
    {
        return $this->participantTokenSecret;
    }

    public function getParticipantTokenTtlSeconds(): int
    {
        return $this->participantTokenTtlSeconds;
    }

    public function getCsrfTokenExpirationMinutes(): int
    {
        return $this->csrfTokenExpirationMinutes;
    }

    public function getMaximumUploadSizeMb(): int
    {
        return $this->maximumUploadSizeMb;
    }

    public function getMaximumUploadSizeBytes(): int
    {
        return $this->maximumUploadSizeMb * self::BYTES_PER_MEGABYTE;
    }

    public function getMaximumUploadPackageLengthBytes(): int
    {
        return $this->getMaximumUploadSizeBytes()
            + self::MULTIPART_OVERHEAD_BYTES;
    }

    public function getQuestionImageStoragePath(): string
    {
        return $this->questionImageStoragePath;
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
        return $this->parseList($extensions, true);
    }

    /**
     * @return string[]
     */
    private function parseList(string $values, bool $lowercase = false): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                static fn(string $value): string => $lowercase
                    ? strtolower(trim($value))
                    : trim($value),
                explode(',', $values),
            ),
            static fn(string $value): bool => $value !== '',
        )));
    }

    private function resolveQuestionImageStoragePath(string $configuredPath): string
    {
        $configuredPath = trim($configuredPath);

        if (
            $configuredPath === ''
            || str_contains($configuredPath, "\0")
        ) {
            throw new InvalidArgumentException(
                'Question image storage path is invalid.',
            );
        }

        $segments = preg_split('#[\\\\/]#', $configuredPath);

        if ($segments === false) {
            throw new InvalidArgumentException(
                'Question image storage path is invalid.',
            );
        }

        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException(
                    'Question image storage path cannot contain parent traversal.',
                );
            }
        }

        if ($this->isAbsolutePath($configuredPath)) {
            $absolutePath = rtrim($configuredPath, '/\\');

            if ($absolutePath === '' || preg_match('#^[A-Za-z]:$#', $absolutePath) === 1) {
                throw new InvalidArgumentException(
                    'Question image storage path cannot be a filesystem root.',
                );
            }

            return $absolutePath;
        }

        $relativeSegments = array_values(array_filter(
            $segments,
            static fn (string $segment): bool => $segment !== '' && $segment !== '.',
        ));

        if ($relativeSegments === []) {
            throw new InvalidArgumentException(
                'Question image storage path must identify a directory.',
            );
        }

        return rtrim(
            $this->environment->getProjectRootPath(),
            '/\\',
        ) . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $relativeSegments);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
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

    private function readParticipantTokenSecret(): string
    {
        if (!$this->environment->has('PARTICIPANT_TOKEN_SECRET')) {
            throw new InvalidArgumentException(
                'Participant token secret must contain at least 32 characters.',
            );
        }

        $secret = $this->environment->get('PARTICIPANT_TOKEN_SECRET');

        if (strlen($secret) < 32) {
            throw new InvalidArgumentException(
                'Participant token secret must contain at least 32 characters.',
            );
        }

        return $secret;
    }

    private function ensurePositive(int $value, string $label): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException(sprintf('%s must be greater than zero.', $label));
        }
    }
}
