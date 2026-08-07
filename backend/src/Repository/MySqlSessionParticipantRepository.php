<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Game\Exception\ParticipantAlreadyJoinedException;
use CodeLandQuiz\Game\Exception\ParticipantNicknameAlreadyExistsException;
use CodeLandQuiz\Model\ParticipantType;
use CodeLandQuiz\Model\SessionParticipantAdminOverview;
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

    private const FIND_ACTIVE_BY_SESSION_ID_SQL = <<<SQL
SELECT
    sp.id,
    sp.session_id,
    sp.participant_type,
    sp.student_id,
    student.first_name AS student_first_name,
    student.last_name AS student_last_name,
    student.username AS student_username,
    sp.nickname,
    sp.avatar_key,
    sp.total_score,
    sp.is_connected,
    sp.disconnected_at,
    sp.joined_at,
    pa.id AS current_answer_id
FROM session_participants sp
LEFT JOIN students student
    ON student.id = sp.student_id
LEFT JOIN participant_answers pa
    ON pa.session_participant_id = sp.id
   AND pa.session_question_id = :current_session_question_id
WHERE sp.session_id = :session_id
  AND sp.is_removed = FALSE
ORDER BY
    sp.joined_at ASC,
    sp.id ASC
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

    private const MARK_REMOVED_SQL = <<<SQL
UPDATE session_participants
SET disconnected_at = CASE
        WHEN is_connected = TRUE
            THEN CURRENT_TIMESTAMP(3)
        ELSE disconnected_at
    END,
    is_connected = FALSE,
    is_removed = TRUE,
    removed_at = CURRENT_TIMESTAMP(3)
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

    public function findActiveBySessionId(
        int $sessionId,
        ?int $currentSessionQuestionId,
    ): array {
        $statement = $this->connection()->prepare(
            self::FIND_ACTIVE_BY_SESSION_ID_SQL,
        );
        $statement->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $this->bindNullableInt(
            $statement,
            ':current_session_question_id',
            $currentSessionQuestionId,
        );
        $statement->execute();

        $participants = [];

        while (($row = $statement->fetch()) !== false) {
            $participants[] = $this->mapRowToAdminOverview($row);
        }

        return $participants;
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

    public function findOverviewByIdForUpdateIncludingRemoved(
        int $participantId,
    ): ?SessionParticipantOverview {
        $sql = self::SELECT_OVERVIEW_SQL
            . "\nWHERE id = :participant_id"
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

    public function markRemoved(int $participantId): void
    {
        $statement = $this->connection()->prepare(self::MARK_REMOVED_SQL);
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

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToAdminOverview(
        array $row,
    ): SessionParticipantAdminOverview {
        return new SessionParticipantAdminOverview(
            id: (int) $row['id'],
            sessionId: (int) $row['session_id'],
            participantType: ParticipantType::from(
                (string) $row['participant_type'],
            ),
            studentId: $this->nullableInt($row['student_id']),
            studentFirstName: $this->nullableString(
                $row['student_first_name'],
            ),
            studentLastName: $this->nullableString(
                $row['student_last_name'],
            ),
            studentUsername: $this->nullableString(
                $row['student_username'],
            ),
            nickname: (string) $row['nickname'],
            avatarKey: (string) $row['avatar_key'],
            totalScore: (int) $row['total_score'],
            isConnected: (bool) (int) $row['is_connected'],
            disconnectedAt: $this->nullableDateTime(
                $row['disconnected_at'],
            ),
            joinedAt: new DateTimeImmutable((string) $row['joined_at']),
            hasAnsweredCurrentQuestion: $row['current_answer_id'] !== null,
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    private function nullableDateTime(mixed $value): ?DateTimeImmutable
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
