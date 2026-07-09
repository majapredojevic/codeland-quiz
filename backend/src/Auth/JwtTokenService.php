<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\Model\User;
use CodeLandQuiz\Support\Environment;
use Firebase\JWT\JWT;

final readonly class JwtTokenService implements JwtService
{
    private const SECONDS_PER_MINUTE = 60;

    public function __construct(
        private readonly AppConfig $config,
        private readonly Environment $environment,
    ) {}

    public function createAccessToken(User $user): string
    {
        return JWT::encode(
            $this->createPayload($user),
            $this->environment->get('JWT_SECRET'),
            $this->environment->get('JWT_ALGORITHM'),
        );
    }

    /**
     * @return array<string, int|string>
     */
    private function createPayload(User $user): array
    {
        $issuedAt = time();
        $expiresAt = $issuedAt + ($this->config->getJwtExpirationMinutes() * self::SECONDS_PER_MINUTE);

        return [
            'sub' => $user->getId(),
            'email' => $user->getEmail(),
            'role' => $user->getRole()->value,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ];
    }
}
