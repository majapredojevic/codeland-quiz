<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use Closure;
use CodeLandQuiz\Auth\Exception\InvalidCredentialsException;
use CodeLandQuiz\Auth\Exception\LoginRateLimitedException;
use CodeLandQuiz\Model\LoginAttempt;
use CodeLandQuiz\Repository\LoginAttemptRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final class LoginAttemptService
{
    private Closure $clock;

    public function __construct(
        private readonly LoginAttemptRepository $loginAttempts,
        private readonly int $accountAttemptLimit,
        private readonly int $lockDurationMinutes,
        private readonly LoginIpRateLimiter $ipRateLimiter,
        ?Closure $clock = null,
    ) {
        if ($this->accountAttemptLimit < 1 || $this->lockDurationMinutes < 1) {
            throw new InvalidArgumentException(
                'Login account rate-limit values must be greater than zero.',
            );
        }

        $this->clock = $clock
            ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function executeLoginAttempt(
        string $email,
        string $clientIdentifier,
        callable $operation,
    ): mixed {
        $reservationId = $this->ipRateLimiter->reserve($clientIdentifier);

        try {
            $result = $this->loginAttempts->synchronizedByEmail(
                $email,
                function () use ($email, $operation): mixed {
                    $this->ensureLoginAllowed($email);

                    return $operation();
                },
            );
        } catch (InvalidCredentialsException|LoginRateLimitedException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            $this->ipRateLimiter->release(
                $clientIdentifier,
                $reservationId,
            );

            throw $throwable;
        }

        $this->ipRateLimiter->release($clientIdentifier, $reservationId);

        return $result;
    }

    public function ensureLoginAllowed(string $email): void
    {
        $since = ($this->clock)()->modify(
            sprintf('-%d minutes', $this->lockDurationMinutes),
        );

        $failedAttempts = $this->loginAttempts->countFailedAttemptsSince($email, $since);

        if ($failedAttempts >= $this->accountAttemptLimit) {
            throw new LoginRateLimitedException(
                retryAfterSeconds: $this->lockDurationMinutes * 60,
            );
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
            attemptedAt: ($this->clock)(),
        );
    }
}
