<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\DTO\RefreshTokenResult;
use CodeLandQuiz\Model\RefreshToken;
use CodeLandQuiz\Model\User;
use CodeLandQuiz\Repository\RefreshTokenRepository;
use DateTimeImmutable;
use RuntimeException;

final readonly class DatabaseRefreshTokenService implements RefreshTokenService
{
    public function __construct(
        private RefreshTokenRepository $refreshTokens,
        private AppConfig $config,
    ) {
    }

    public function create(User $user): string
    {
        return $this->createForUserId($user->getId())['plainToken'];
    }

    public function rotate(string $refreshToken): RefreshTokenResult
    {
        $existingToken = $this->refreshTokens->findValidByTokenHash(
            $this->hashToken($refreshToken),
        );

        if ($existingToken === null) {
            throw new RuntimeException('Refresh token is invalid or expired.');
        }

        $newToken = $this->createForUserId($existingToken->getUserId());

        $this->refreshTokens->revoke(
            $this->existingTokenId($existingToken),
            $newToken['id'],
        );

        return new RefreshTokenResult(
            userId: $existingToken->getUserId(),
            refreshToken: $newToken['plainToken'],
        );
    }

    public function revoke(string $refreshToken): void
    {
        $existingToken = $this->refreshTokens->findValidByTokenHash(
            $this->hashToken($refreshToken),
        );

        if ($existingToken === null) {
            return;
        }

        $this->refreshTokens->revoke($this->existingTokenId($existingToken));
    }

    /**
     * @return array{id: int, plainToken: string}
     */
    private function createForUserId(int $userId): array
    {
        $plainToken = bin2hex(random_bytes(64));
        $expiresAt = (new DateTimeImmutable())->modify(
            sprintf('+%d days', $this->config->getRefreshTokenExpirationDays()),
        );

        $refreshToken = new RefreshToken(
            id: null,
            userId: $userId,
            tokenHash: $this->hashToken($plainToken),
            expiresAt: $expiresAt,
        );

        $id = $this->refreshTokens->save($refreshToken);

        return [
            'id' => $id,
            'plainToken' => $plainToken,
        ];
    }

    private function hashToken(string $plainToken): string
    {
        return hash_hmac(
            'sha256',
            $plainToken,
            $this->config->getRefreshTokenHashKey(),
        );
    }

    private function existingTokenId(RefreshToken $refreshToken): int
    {
        $id = $refreshToken->getId();

        if ($id === null) {
            throw new RuntimeException('Persisted refresh token is missing an ID.');
        }

        return $id;
    }
}
