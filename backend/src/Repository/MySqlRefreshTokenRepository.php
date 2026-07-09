<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\RefreshToken;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class MySqlRefreshTokenRepository implements RefreshTokenRepository
{
    private const INSERT_SQL = <<<SQL
INSERT INTO refresh_tokens (
    user_id,
    token_hash,
    expires_at,
    revoked_at,
    replaced_by_token_id
) VALUES (
    :user_id,
    :token_hash,
    :expires_at,
    :revoked_at,
    :replaced_by_token_id
)
SQL;

   private const FIND_VALID_SQL = <<<SQL
SELECT id, user_id, token_hash, expires_at, revoked_at, replaced_by_token_id
FROM refresh_tokens
WHERE revoked_at IS NULL
  AND expires_at > CURRENT_TIMESTAMP
ORDER BY id DESC
LIMIT 100
SQL;

    private const REVOKE_SQL = <<<SQL
UPDATE refresh_tokens
SET revoked_at = CURRENT_TIMESTAMP,
    replaced_by_token_id = :replaced_by_token_id
WHERE id = :id
SQL;

    public function __construct(
        private readonly Database $database,
    ) {
    }

    public function save(RefreshToken $refreshToken): int
    {
        $statement = $this->connection()->prepare(self::INSERT_SQL);

        $statement->execute([
            'user_id' => $refreshToken->getUserId(),
            'token_hash' => $refreshToken->getTokenHash(),
            'expires_at' => $this->formatDateTime($refreshToken->getExpiresAt()),
            'revoked_at' => $this->formatNullableDateTime($refreshToken->getRevokedAt()),
            'replaced_by_token_id' => $refreshToken->getReplacedByTokenId(),
        ]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('Refresh token was not inserted.');
        }

        $id = (int) $this->connection()->lastInsertId();

        if ($id < 1) {
            throw new RuntimeException('Refresh token ID was not returned.');
        }

        return $id;
    }

    public function findValidByPlainToken(string $plainToken): ?RefreshToken
    {
        $statement = $this->connection()->prepare(self::FIND_VALID_SQL);
        $statement->execute();

        while (($row = $statement->fetch()) !== false) {
            if (password_verify($plainToken, (string) $row['token_hash'])) {
                return $this->mapRowToRefreshToken($row);
            }
        }

        return null;
    }

    public function revoke(int $refreshTokenId, ?int $replacedByTokenId = null): void
    {
        $statement = $this->connection()->prepare(self::REVOKE_SQL);

        $statement->execute([
            'id' => $refreshTokenId,
            'replaced_by_token_id' => $replacedByTokenId,
        ]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('Refresh token was not revoked.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToRefreshToken(array $row): RefreshToken
    {
        return new RefreshToken(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            tokenHash: (string) $row['token_hash'],
            expiresAt: new DateTimeImmutable((string) $row['expires_at']),
            revokedAt: $this->mapNullableDateTime($row['revoked_at']),
            replacedByTokenId: $row['replaced_by_token_id'] === null
                ? null
                : (int) $row['replaced_by_token_id'],
        );
    }

    private function formatDateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s');
    }

    private function formatNullableDateTime(?DateTimeImmutable $dateTime): ?string
    {
        if ($dateTime === null) {
            return null;
        }

        return $this->formatDateTime($dateTime);
    }

    private function mapNullableDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return new DateTimeImmutable((string) $value);
    }

    private function connection(): PDO
    {
        return $this->database->getConnection();
    }
}
