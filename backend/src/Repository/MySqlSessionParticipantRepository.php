<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Game\Exception\ParticipantAlreadyJoinedException;
use CodeLandQuiz\Game\Exception\ParticipantNicknameAlreadyExistsException;
use CodeLandQuiz\Model\ParticipantType;
use CodeLandQuiz\Model\SessionParticipantOverview;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final readonly class MySqlSessionParticipantRepository implements SessionParticipantRepository
{
    private const SELECT_OVERVIEW_SQL = <<<SQL
SELECT
    id,
    session_id,
    participant_type,
    student_id,
    nickname,
    avatar_key,
    total_score,
    is_connected,
    is_removed,
    joined_at
FROM session_participants
SQL;

    private const INSERT_SQL = <<<SQL
INSERT INTO session_participants (
    session_id,
    participant_type,
    student_id,
    nickname,
    avatar_key,
    total_score,
    is_connected,
    disconnected_at,
    is_removed,
    removed_at
) VALUES (
    :session_id,
    :participant_type,
    :student_id,
    :nickname,
    :avatar_key,
    0,
    FALSE,
    NULL,
    FALSE,
    NULL
)
SQL;

    private const MARK_CONNECTED_SQL = <<<SQL
UPDATE session_participants
SET is_connected = TRUE,
    disconnected_at = NULL
WHERE id = :participant_id
  AND is_removed = FALSE
SQL;

    private const MARK_DISCONNECTED_SQL = <<<SQL
UPDATE session_participants
SET is_connected = FALSE,
    disconnected_at = CURRENT_TIMESTAMP
WHERE id = :participant_id
  AND is_removed = FALSE
SQL;

    public function __construct(
        private Database $database,
    ) {
    }

    public function findActiveBySessionAndStudentId(
        int $sessionId,
        int $studentId,
    ): ?SessionParticipantOverview {
        $sql = self::SELECT_OVERVIEW_SQL
            . "\nWHERE session_id = :session_id"
            . "\n  AND student_id = :student_id"
            . "\n  AND is_removed = FALSE"
            . "\nLIMIT 1";
        $statement = $this->connection()->prepare($sql);
        $statement->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $statement->bindValue(':student_id', $studentId, PDO::PARAM_INT);
        $statement->execute();

        return $this->fetchOverview($statement);
    }

    public function findActiveBySessionAndNickname(
        int $sessionId,
        string $nickname,
    ): ?SessionParticipantOverview {
        $sql = self::SELECT_OVERVIEW_SQL
            . "\nWHERE session_id = :session_id"
            . "\n  AND nickname = :nickname"
            . "\n  AND is_removed = FALSE"
            . "\nLIMIT 1";
        $statement = $this->connection()->prepare($sql);
        $statement->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $statement->bindValue(':nickname', $nickname);
        $statement->execute();

        return $this->fetchOverview($statement);
    }

    public function create(
        int $sessionId,
        ParticipantType $participantType,
        ?int $studentId,
        string $nickname,
        string $avatarKey,
    ): int {
        $statement = $this->connection()->prepare(self::INSERT_SQL);
        $statement->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $statement->bindValue(':participant_type', $participantType->value);
        $this->bindNullableInt($statement, ':student_id', $studentId);
        $statement->bindValue(':nickname', $nickname);
        $statement->bindValue(':avatar_key', $avatarKey);

        try {
            $statement->execute();
        } catch (PDOException $exception) {
            $this->throwParticipantConflictIfNeeded($exception);

            throw $exception;
        }

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('Session participant was not created.');
        }

        $id = (int) $this->connection()->lastInsertId();

        if ($id < 1) {
            throw new RuntimeException('Session participant ID was not returned.');
        }

        return $id;
    }

    public function findOverviewById(
        int $participantId,
    ): ?SessionParticipantOverview {
        $sql = self::SELECT_OVERVIEW_SQL
            . "\nWHERE id = :participant_id"
            . "\n  AND is_removed = FALSE"
            . "\nLIMIT 1";
        $statement = $this->connection()->prepare($sql);
        $statement->bindValue(':participant_id', $participantId, PDO::PARAM_INT);
        $statement->execute();

        return $this->fetchOverview($statement);
    }

    public function findOverviewByIdForUpdate(
        int $participantId,
    ): ?SessionParticipantOverview {
        $sql = self::SELECT_OVERVIEW_SQL
            . "\nWHERE id = :participant_id"
            . "\n  AND is_removed = FALSE"
            . "\nFOR UPDATE";
        $statement = $this->connection()->prepare($sql);
        $statement->bindValue(':participant_id', $participantId, PDO::PARAM_INT);
        $statement->execute();

        return $this->fetchOverview($statement);
    }

    public function markConnected(int $participantId): void
    {
        $statement = $this->connection()->prepare(self::MARK_CONNECTED_SQL);
        $statement->bindValue(':participant_id', $participantId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function markDisconnected(int $participantId): void
    {
        $statement = $this->connection()->prepare(self::MARK_DISCONNECTED_SQL);
        $statement->bindValue(':participant_id', $participantId, PDO::PARAM_INT);
        $statement->execute();
    }

    private function bindNullableInt(
        PDOStatement $statement,
        string $parameter,
        ?int $value,
    ): void {
        if ($value === null) {
            $statement->bindValue($parameter, null, PDO::PARAM_NULL);

            return;
        }

        $statement->bindValue($parameter, $value, PDO::PARAM_INT);
    }

    private function throwParticipantConflictIfNeeded(
        PDOException $exception,
    ): void {
        $errorInfo = $exception->errorInfo;
        $sqlState = (string) ($errorInfo[0] ?? '');
        $driverCode = (int) ($errorInfo[1] ?? 0);
        $message = (string) ($errorInfo[2] ?? $exception->getMessage());

        if ($sqlState !== '23000' || $driverCode !== 1062) {
            return;
        }

        if (str_contains($message, 'uq_session_participants_active_student')) {
            throw new ParticipantAlreadyJoinedException(
                'This student has already joined the game.',
                0,
                $exception,
            );
        }

        if (str_contains($message, 'uq_session_participants_active_nickname')) {
            throw new ParticipantNicknameAlreadyExistsException(
                'This nickname is already in use in the game.',
                0,
                $exception,
            );
        }
    }

    private function fetchOverview(
        PDOStatement $statement,
    ): ?SessionParticipantOverview {
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToOverview($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToOverview(array $row): SessionParticipantOverview
    {
        return new SessionParticipantOverview(
            id: (int) $row['id'],
            sessionId: (int) $row['session_id'],
            participantType: ParticipantType::from((string) $row['participant_type']),
            studentId: $row['student_id'] === null
                ? null
                : (int) $row['student_id'],
            nickname: (string) $row['nickname'],
            avatarKey: (string) $row['avatar_key'],
            totalScore: (int) $row['total_score'],
            isConnected: (bool) (int) $row['is_connected'],
            isRemoved: (bool) (int) $row['is_removed'],
            joinedAt: new DateTimeImmutable((string) $row['joined_at']),
        );
    }

    private function connection(): PDO
    {
        return $this->database->getConnection();
    }
}
