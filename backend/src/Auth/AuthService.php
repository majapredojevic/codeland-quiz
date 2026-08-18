<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\Auth\Exception\InvalidCredentialsException;
use CodeLandQuiz\DTO\LoginDTO;
use CodeLandQuiz\DTO\LoginResult;
use CodeLandQuiz\DTO\RefreshResult;
use CodeLandQuiz\Model\AuditAction;
use CodeLandQuiz\Repository\UserRepository;
use RuntimeException;

final readonly class AuthService
{
    private const SECONDS_PER_MINUTE = 60;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $passwordHasher,
        private readonly JwtService $jwtService,
        private readonly RefreshTokenService $refreshTokenService,
        private readonly CsrfTokenService $csrfTokenService,
        private readonly LoginAttemptService $loginAttemptService,
        private readonly AuditLogService $auditLogService,
        private readonly AppConfig $config,
    ) {
    }

    public function login(
        LoginDTO $dto,
        ?string $userAgent = null,
        string $clientIdentifier = 'unknown',
    ): LoginResult
    {
        $user = $this->loginAttemptService->executeLoginAttempt(
            email: $dto->email,
            clientIdentifier: $clientIdentifier,
            operation: function () use ($dto, $userAgent) {
                $user = $this->users->findByEmailIncludingInactive(
                    $dto->email,
                );

                if (
                    $user === null
                    || !$this->passwordHasher->verify(
                        $dto->password,
                        $user->getPasswordHash(),
                    )
                    || !$user->isActive()
                    || !$user->canUseNormalLogin()
                ) {
                    $this->loginAttemptService->recordFailure(
                        $dto->email,
                        $userAgent,
                    );
                    $this->auditLogService->log(
                        action: AuditAction::LOGIN_FAILED,
                        userId: $user?->getId(),
                        metadata: ['email' => $dto->email],
                    );

                    throw new InvalidCredentialsException(
                        'Invalid credentials.',
                    );
                }

                if ($this->passwordHasher->needsRehash($user->getPasswordHash())) {
                    $user->changePasswordHash(
                        $this->passwordHasher->hash($dto->password),
                    );
                    $this->users->save($user);
                }

                $this->loginAttemptService->recordSuccess(
                    $dto->email,
                    $userAgent,
                );
                $this->auditLogService->log(
                    action: AuditAction::LOGIN_SUCCESS,
                    userId: $user->getId(),
                );

                return $user;
            },
        );

        $accessToken = $this->jwtService->createAccessToken($user);
        $refreshToken = $this->refreshTokenService->create($user);
        $csrfToken = $this->csrfTokenService->generate();

        return new LoginResult(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            csrfToken: $csrfToken,
            expiresInSeconds: $this->getAccessTokenExpirationSeconds(),
            userId: $user->getId(),
            userName: $user->getName(),
            userEmail: $user->getEmail(),
            userRole: $user->getRole(),
            mustChangePassword: $user->mustChangePassword(),
        );
    }

    private function getAccessTokenExpirationSeconds(): int
    {
        return $this->config->getJwtExpirationMinutes() * self::SECONDS_PER_MINUTE;
    }

    public function refresh(string $plainRefreshToken): RefreshResult
    {
        $rotatedToken = $this->refreshTokenService->rotate($plainRefreshToken);

        $user = $this->users->findById($rotatedToken->userId);

        if ($user === null || !$user->isActive()) {
            throw new RuntimeException('Refresh token user is unavailable.');
        }

        return new RefreshResult(
            accessToken: $this->jwtService->createAccessToken($user),
            refreshToken: $rotatedToken->refreshToken,
            csrfToken: $this->csrfTokenService->generate(),
            expiresInSeconds: $this->getAccessTokenExpirationSeconds(),
        );
    }
}
