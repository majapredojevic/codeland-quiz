<?php

declare(strict_types=1);

namespace CodeLandQuiz\Game;

use CodeLandQuiz\DTO\ParticipantTokenPayloadDTO;
use CodeLandQuiz\Game\Exception\InvalidParticipantTokenException;
use CodeLandQuiz\Model\ParticipantType;
use DateTimeImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Throwable;

final readonly class JwtParticipantTokenVerifier implements ParticipantTokenVerifier
{
    private const ISSUER = 'codeland-quiz';
    private const AUDIENCE = 'codeland-quiz-participant';
    private const TOKEN_TYPE = 'PARTICIPANT';
    private const ALGORITHM = 'HS256';

    public function __construct(
        private string $secret,
    ) {
        if (strlen($this->secret) < 32) {
            throw new InvalidArgumentException(
                'Participant token secret must contain at least 32 characters.',
            );
        }
    }

    public function verify(string $token): ParticipantTokenPayloadDTO
    {
        try {
            $claims = JWT::decode(
                $token,
                new Key($this->secret, self::ALGORITHM),
            );

            return $this->mapClaims($claims);
        } catch (Throwable) {
            throw new InvalidParticipantTokenException(
                'Participant authentication failed.',
            );
        }
    }

    private function mapClaims(stdClass $claims): ParticipantTokenPayloadDTO
    {
        $this->ensureStringClaim($claims, 'iss', self::ISSUER);
        $this->ensureStringClaim($claims, 'aud', self::AUDIENCE);
        $this->ensureStringClaim($claims, 'tokenType', self::TOKEN_TYPE);

        $participantId = $this->positiveIntegerStringClaim($claims, 'sub');
        $sessionId = $this->positiveIntegerClaim($claims, 'sessionId');
        $participantType = $this->participantTypeClaim($claims);
        $studentId = $this->studentIdClaim($claims, $participantType);
        $issuedAt = $this->positiveTimestampClaim($claims, 'iat');
        $expiresAt = $this->positiveTimestampClaim($claims, 'exp');

        if ($expiresAt <= time()) {
            throw new RuntimeException('Participant token has expired.');
        }

        $jwtId = $claims->jti ?? null;

        if (!is_string($jwtId) || trim($jwtId) === '') {
            throw new RuntimeException('Participant token ID is invalid.');
        }

        return new ParticipantTokenPayloadDTO(
            participantId: $participantId,
            sessionId: $sessionId,
            participantType: $participantType,
            studentId: $studentId,
            issuedAt: (new DateTimeImmutable())->setTimestamp($issuedAt),
            expiresAt: (new DateTimeImmutable())->setTimestamp($expiresAt),
            jwtId: $jwtId,
        );
    }

    private function ensureStringClaim(
        stdClass $claims,
        string $name,
        string $expected,
    ): void {
        $value = $claims->{$name} ?? null;

        if ($value !== $expected) {
            throw new RuntimeException('Participant token claim is invalid.');
        }
    }

    private function positiveIntegerStringClaim(
        stdClass $claims,
        string $name,
    ): int {
        $value = $claims->{$name} ?? null;

        if (
            !is_string($value)
            || filter_var($value, FILTER_VALIDATE_INT) === false
            || (int) $value < 1
        ) {
            throw new RuntimeException('Participant token claim is invalid.');
        }

        return (int) $value;
    }

    private function positiveIntegerClaim(stdClass $claims, string $name): int
    {
        $value = $claims->{$name} ?? null;

        if (!is_int($value) || $value < 1) {
            throw new RuntimeException('Participant token claim is invalid.');
        }

        return $value;
    }

    private function positiveTimestampClaim(stdClass $claims, string $name): int
    {
        return $this->positiveIntegerClaim($claims, $name);
    }

    private function participantTypeClaim(stdClass $claims): ParticipantType
    {
        $value = $claims->participantType ?? null;

        if (!is_string($value)) {
            throw new RuntimeException('Participant token claim is invalid.');
        }

        $participantType = ParticipantType::tryFrom($value);

        if ($participantType === null) {
            throw new RuntimeException('Participant token claim is invalid.');
        }

        return $participantType;
    }

    private function studentIdClaim(
        stdClass $claims,
        ParticipantType $participantType,
    ): ?int {
        $value = $claims->studentId ?? null;

        if ($participantType === ParticipantType::GUEST) {
            if ($value !== null) {
                throw new RuntimeException(
                    'Participant token claim is invalid.',
                );
            }

            return null;
        }

        if (!is_int($value) || $value < 1) {
            throw new RuntimeException('Participant token claim is invalid.');
        }

        return $value;
    }
}
