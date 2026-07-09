<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\Model\LoginAttempt;
use CodeLandQuiz\Repository\LoginAttemptRepository;
use DateTimeImmutable;
use RuntimeException;

final readonly class LoginAttemptService
{
    public function __construct(
        private readonly LoginAttemptRepository $loginAttempts,
        private readonly AppConfig $config,
    ) {}

    public function ensureLoginAllowed(string $email): void
    {
        $since = (new DateTimeImmutable())->modify(
            sprintf('-%d minutes', $this->config->getLoginLockDurationMinutes()),
        );

        $failedAttempts = $this->loginAttempts->countFailedAttemptsSince($email, $since);

        if ($failedAttempts >= $this->config->getLoginAttemptLimit()) {
            throw new RuntimeException('Too many failed login attempts. Please try again later.');
        }
    }

    public function recordFailure(string $email, ?string $userAgent = null): void
    {
        $this->loginAttempts->save($this->createAttempt($email, false, $userAgent));
    }

    public function recordSuccess(string $email, ?string $userAgent = null): void
    {
        $this->loginAttempts->clearAttempts($email);
        $this->loginAttempts->save($this->createAttempt($email, true, $userAgent));
    }

    private function createAttempt(
        string $email,
        bool $successful,
        ?string $userAgent,
    ): LoginAttempt {
        return new LoginAttempt(
            id: null,
            email: $email,
            successful: $successful,
            userAgent: $userAgent,
            attemptedAt: new DateTimeImmutable(),
        );
    }
}
