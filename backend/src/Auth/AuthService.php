<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\DTO\LoginDTO;
use CodeLandQuiz\DTO\LoginResult;
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
        private readonly AppConfig $config,
    ) {}

    public function login(LoginDTO $dto): LoginResult
    {
        $user = $this->users->findByEmail($dto->email);

        if ($user === null) {
            throw new RuntimeException('Invalid credentials.');
        }

        if (!$this->passwordHasher->verify($dto->password, $user->getPasswordHash())) {
            throw new RuntimeException('Invalid credentials.');
        }

        if ($this->passwordHasher->needsRehash($user->getPasswordHash())) {
            $user->changePasswordHash($this->passwordHasher->hash($dto->password));
            $this->users->save($user);
        }

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
        );
    }

    private function getAccessTokenExpirationSeconds(): int
    {
        return $this->config->getJwtExpirationMinutes() * self::SECONDS_PER_MINUTE;
    }
}
