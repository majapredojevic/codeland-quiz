<?php

declare(strict_types=1);

namespace CodeLandQuiz\Game;

use CodeLandQuiz\DTO\IssuedParticipantTokenDTO;
use CodeLandQuiz\Model\SessionParticipantOverview;
use DateTimeImmutable;
use Firebase\JWT\JWT;
use InvalidArgumentException;

final readonly class JwtParticipantTokenIssuer implements ParticipantTokenIssuer
{
    private const ALGORITHM = 'HS256';
    private const ISSUER = 'codeland-quiz';
    private const AUDIENCE = 'codeland-quiz-participant';
    private const TOKEN_TYPE = 'PARTICIPANT';

    public function __construct(
        private string $secret,
        private int $ttlSeconds,
    ) {
        if (strlen($this->secret) < 32) {
            throw new InvalidArgumentException(
                'Participant token secret must contain at least 32 characters.',
            );
        }

        if ($this->ttlSeconds < 1) {
            throw new InvalidArgumentException(
                'Participant token TTL must be greater than zero.',
            );
        }
    }

    public function issue(
        SessionParticipantOverview $participant,
    ): IssuedParticipantTokenDTO {
        $issuedAt = time();
        $expiresAt = $issuedAt + $this->ttlSeconds;

        return new IssuedParticipantTokenDTO(
            token: JWT::encode(
                [
                    'iss' => self::ISSUER,
                    'aud' => self::AUDIENCE,
                    'tokenType' => self::TOKEN_TYPE,
                    'sub' => (string) $participant->id,
                    'sessionId' => $participant->sessionId,
                    'participantType' => $participant->participantType->value,
                    'studentId' => $participant->studentId,
                    'iat' => $issuedAt,
                    'exp' => $expiresAt,
                    'jti' => bin2hex(random_bytes(16)),
                ],
                $this->secret,
                self::ALGORITHM,
            ),
            expiresAt: (new DateTimeImmutable())->setTimestamp($expiresAt),
        );
    }
}
