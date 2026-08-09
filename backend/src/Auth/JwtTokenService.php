<?php

declare(strict_types=1);

namespace CodeLandQuiz\Auth;

use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\DTO\AccessTokenPayload;
use CodeLandQuiz\Model\User;
use CodeLandQuiz\Model\UserRole;
use CodeLandQuiz\Support\Environment;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ValueError;

final readonly class JwtTokenService implements JwtService
{
    private const SECONDS_PER_MINUTE = 60;

    private string $secret;

    private string $algorithm;

    public function __construct(
        private readonly AppConfig $config,
        Environment $environment,
    ) {
        $this->secret = $environment->get('JWT_SECRET');
        $this->algorithm = $environment->get('JWT_ALGORITHM');

        if (strlen($this->secret) < 32) {
            throw new InvalidArgumentException(
                'JWT secret must contain at least 32 characters.',
            );
        }

        if ($this->algorithm !== 'HS256') {
            throw new InvalidArgumentException('JWT algorithm must be HS256.');
        }
    }

    public function createAccessToken(User $user): string
    {
        return JWT::encode(
            $this->createPayload($user),
            $this->secret,
            $this->algorithm,
        );
    }

    public function decodeAccessToken(string $jwt): AccessTokenPayload
    {
        $decoded = JWT::decode(
            $jwt,
            new Key(
                $this->secret,
                $this->algorithm,
            ),
        );

        return new AccessTokenPayload(
            userId: $this->requiredIntClaim($decoded, 'sub'),
            email: $this->requiredStringClaim($decoded, 'email'),
            role: $this->roleClaim($decoded),
            issuedAt: $this->requiredIntClaim($decoded, 'iat'),
            expiresAt: $this->requiredIntClaim($decoded, 'exp'),
        );
    }

    public function isAccessTokenValid(string $jwt): bool
    {
        try {
            $this->decodeAccessToken($jwt);

            return true;
        } catch (Throwable) {
            return false;
        }
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

    private function requiredIntClaim(object $decoded, string $claim): int
    {
        if (!property_exists($decoded, $claim) || !is_int($decoded->{$claim})) {
            throw new RuntimeException(sprintf('Access token claim "%s" is missing or invalid.', $claim));
        }

        return $decoded->{$claim};
    }

    private function requiredStringClaim(object $decoded, string $claim): string
    {
        if (!property_exists($decoded, $claim) || !is_string($decoded->{$claim})) {
            throw new RuntimeException(sprintf('Access token claim "%s" is missing or invalid.', $claim));
        }

        return $decoded->{$claim};
    }

    private function roleClaim(object $decoded): UserRole
    {
        $role = $this->requiredStringClaim($decoded, 'role');

        try {
            return UserRole::from($role);
        } catch (ValueError $exception) {
            throw new RuntimeException('Access token claim "role" is invalid.', 0, $exception);
        }
    }
}
