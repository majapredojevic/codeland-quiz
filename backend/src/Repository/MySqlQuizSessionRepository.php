<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\QuestionType;
use CodeLandQuiz\Model\QuizSessionOverview;
use CodeLandQuiz\Model\QuizSessionStatus;
use CodeLandQuiz\QuizSession\Exception\GamePinAlreadyExistsException;
use CodeLandQuiz\Support\Database;
use DateTimeImmutable;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final readonly class MySqlQuizSessionRepository implements QuizSessionRepository
{
    private const INSERT_SQL = <<<SQL
INSERT INTO quiz_sessions (
    quiz_id,
    host_user_id,
    quiz_title,
    quiz_version,
    game_pin,
    status,
    current_question_order,
    join_deadline,
    started_at,
    ended_at
) VALUES (
    :quiz_id,
    :host_user_id,
    :quiz_title,
    :quiz_version,
    :game_pin,
    'WAITING',
    NULL,
    NULL,
    NULL,
    NULL
)
SQL;

    private const INSERT_SNAPSHOT_QUESTION_SQL = <<<SQL
INSERT INTO session_questions (
    session_id,
    source_question_id,
    question_text,
    question_type,
    image_path,
    time_limit_seconds,
    max_points,
    question_order
) VALUES (
    :session_id,
    :source_question_id,
    :question_text,
    :question_type,
    :image_path,
    :time_limit_seconds,
    :max_points,
    :question_order
)
SQL;

    private const INSERT_SNAPSHOT_OPTION_SQL = <<<SQL
INSERT INTO session_question_options (
    session_question_id,
    source_option_id,
    option_text,
    is_correct,
    option_order
) VALUES (
    :session_question_id,
    :source_option_id,
    :option_text,
    :is_correct,
    :option_order
)
SQL;

    private const SELECT_OVERVIEW_SQL = <<<SQL
SELECT
    qs.id,
    qs.quiz_id,
    qs.host_user_id,
    host.name AS host_user_name,
    qs.quiz_title,
    qs.quiz_version,
    qs.game_pin,
    qs.status,
    qs.current_question_order,
    qs.current_question_started_at,
    qs.current_question_deadline,
    qs.current_question_closed_at,
    qs.join_deadline,
    qs.started_at,
    qs.ended_at,
    qs.created_at,
    (
        SELECT COUNT(*)
        FROM session_questions sq
        WHERE sq.session_id = qs.id
    ) AS question_count,
    (
        SELECT COUNT(*)
        FROM session_participants sp
        WHERE sp.session_id = qs.id
          AND sp.is_removed = FALSE
    ) AS participant_count
FROM quiz_sessions qs
INNER JOIN users host
    ON host.id = qs.host_user_id
SQL;

    private const MARK_STARTED_SQL = <<<SQL
UPDATE quiz_sessions
SET status = 'ACTIVE',
    started_at = COALESCE(
        started_at,
        CURRENT_TIMESTAMP
    ),
    current_question_order = :question_order,
    current_question_started_at = CURRENT_TIMESTAMP(3),
    current_question_deadline = TIMESTAMPADD(
        SECOND,
        :time_limit_seconds,
        CURRENT_TIMESTAMP(3)
    ),
    current_question_closed_at = NULL
WHERE id = :session_id
  AND status = 'WAITING'
SQL;

    private const MARK_CURRENT_QUESTION_CLOSED_SQL = <<<SQL
UPDATE quiz_sessions
SET current_question_closed_at = CURRENT_TIMESTAMP(3)
WHERE id = :session_id
  AND status = 'ACTIVE'
  AND current_question_closed_at IS NULL
SQL;

    public function __construct(
        private Database $database,
    ) {}

    public function create(
        int $quizId,
        int $hostUserId,
        string $quizTitle,
        int $quizVersion,
        string $gamePin,
    ): int {
        $statement = $this->connection()->prepare(self::INSERT_SQL);
        $statement->bindValue(':quiz_id', $quizId, PDO::PARAM_INT);
        $statement->bindValue(':host_user_id', $hostUserId, PDO::PARAM_INT);
        $statement->bindValue(':quiz_title', $quizTitle);
        $statement->bindValue(':quiz_version', $quizVersion, PDO::PARAM_INT);
        $statement->bindValue(':game_pin', $gamePin);

        try {
            $statement->execute();
        } catch (PDOException $exception) {
            $this->throwGamePinConflictIfNeeded($exception);

            throw $exception;
        }

        return $this->lastInsertId('Quiz session ID was not returned.');
    }

    public function createSnapshotQuestion(
        int $sessionId,
        int $sourceQuestionId,
        string $questionText,
        QuestionType $questionType,
        ?string $imagePath,
        int $timeLimitSeconds,
        int $maxPoints,
        int $questionOrder,
    ): int {
        $statement = $this->connection()->prepare(
            self::INSERT_SNAPSHOT_QUESTION_SQL,
        );
        $statement->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $statement->bindValue(
            ':source_question_id',
            $sourceQuestionId,
            PDO::PARAM_INT,
        );
        $statement->bindValue(':question_text', $questionText);
        $statement->bindValue(':question_type', $questionType->value);
        $this->bindNullableString($statement, ':image_path', $imagePath);
        $statement->bindValue(
            ':time_limit_seconds',
            $timeLimitSeconds,
            PDO::PARAM_INT,
        );
        $statement->bindValue(':max_points', $maxPoints, PDO::PARAM_INT);
        $statement->bindValue(
            ':question_order',
            $questionOrder,
            PDO::PARAM_INT,
        );
        $statement->execute();

        return $this->lastInsertId(
            'Session question ID was not returned.',
        );
    }

    public function createSnapshotOption(
        int $sessionQuestionId,
        int $sourceOptionId,
        string $optionText,
        bool $isCorrect,
        int $optionOrder,
    ): int {
        $statement = $this->connection()->prepare(
            self::INSERT_SNAPSHOT_OPTION_SQL,
        );
        $statement->bindValue(
            ':session_question_id',
            $sessionQuestionId,
            PDO::PARAM_INT,
        );
        $statement->bindValue(
            ':source_option_id',
            $sourceOptionId,
            PDO::PARAM_INT,
        );
        $statement->bindValue(':option_text', $optionText);
        $statement->bindValue(
            ':is_correct',
            $isCorrect ? 1 : 0,
            PDO::PARAM_INT,
        );
        $statement->bindValue(':option_order', $optionOrder, PDO::PARAM_INT);
        $statement->execute();

        return $this->lastInsertId(
            'Session question option ID was not returned.',
        );
    }

    public function findOverviewById(
        int $sessionId,
    ): ?QuizSessionOverview {
        $statement = $this->connection()->prepare(
            self::SELECT_OVERVIEW_SQL . "\nWHERE qs.id = :session_id",
        );
        $statement->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $statement->execute();

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToOverview($row);
    }

    public function findOverviewByIdForUpdate(
        int $sessionId,
    ): ?QuizSessionOverview {
        $statement = $this->connection()->prepare(
            self::SELECT_OVERVIEW_SQL
                . "\nWHERE qs.id = :session_id\nFOR UPDATE",
        );
        $statement->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $statement->execute();

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToOverview($row);
    }

    public function findOverviewByActiveGamePin(
        string $gamePin,
    ): ?QuizSessionOverview {
        return $this->findOverviewByGamePin(
            $gamePin,
            shouldLock: false,
        );
    }

    public function findOverviewByActiveGamePinForUpdate(
        string $gamePin,
    ): ?QuizSessionOverview {
        return $this->findOverviewByGamePin(
            $gamePin,
            shouldLock: true,
        );
    }

    public function markStarted(
        int $sessionId,
        int $questionOrder,
        int $timeLimitSeconds,
    ): void {
        $statement = $this->connection()->prepare(self::MARK_STARTED_SQL);
        $statement->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $statement->bindValue(
            ':question_order',
            $questionOrder,
            PDO::PARAM_INT,
        );
        $statement->bindValue(
            ':time_limit_seconds',
            $timeLimitSeconds,
            PDO::PARAM_INT,
        );
        $statement->execute();

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('Quiz session was not started.');
        }
    }

    public function markCurrentQuestionClosed(
        int $sessionId,
    ): void {
        $statement = $this->connection()->prepare(
            self::MARK_CURRENT_QUESTION_CLOSED_SQL,
        );
        $statement->bindValue(':session_id', $sessionId, PDO::PARAM_INT);
        $statement->execute();
    }

    private function findOverviewByGamePin(
        string $gamePin,
        bool $shouldLock,
    ): ?QuizSessionOverview {
        $sql = self::SELECT_OVERVIEW_SQL
            . "\nWHERE qs.active_game_pin = :game_pin\nLIMIT 1";

        if ($shouldLock) {
            $sql .= "\nFOR UPDATE";
        }

        $statement = $this->connection()->prepare($sql);
        $statement->bindValue(':game_pin', $gamePin);
        $statement->execute();

        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return $this->mapRowToOverview($row);
    }

    private function bindNullableString(
        PDOStatement $statement,
        string $parameter,
        ?string $value,
    ): void {
        if ($value === null) {
            $statement->bindValue($parameter, null, PDO::PARAM_NULL);

            return;
        }

        $statement->bindValue($parameter, $value, PDO::PARAM_STR);
    }

    private function lastInsertId(string $message): int
    {
        $id = (int) $this->connection()->lastInsertId();

        if ($id < 1) {
            throw new RuntimeException($message);
        }

        return $id;
    }

    private function throwGamePinConflictIfNeeded(
        PDOException $exception,
    ): void {
        $errorInfo = $exception->errorInfo;
        $sqlState = (string) ($errorInfo[0] ?? '');
        $driverCode = (int) ($errorInfo[1] ?? 0);
        $message = (string) ($errorInfo[2] ?? $exception->getMessage());

        if (
            $sqlState === '23000'
            && $driverCode === 1062
            && (
                str_contains($message, 'uq_quiz_sessions_active_game_pin')
                || str_contains($message, 'active_game_pin')
            )
        ) {
            throw new GamePinAlreadyExistsException(
                'Generated game PIN is already in use.',
                0,
                $exception,
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToOverview(array $row): QuizSessionOverview
    {
        return new QuizSessionOverview(
            id: (int) $row['id'],
            quizId: (int) $row['quiz_id'],
            hostUserId: (int) $row['host_user_id'],
            hostUserName: (string) $row['host_user_name'],
            quizTitle: (string) $row['quiz_title'],
            quizVersion: (int) $row['quiz_version'],
            gamePin: (string) $row['game_pin'],
            status: QuizSessionStatus::from((string) $row['status']),
            currentQuestionOrder: $row['current_question_order'] === null
                ? null
                : (int) $row['current_question_order'],
            currentQuestionStartedAt: $this->nullableDateTime(
                $row['current_question_started_at'],
            ),
            currentQuestionDeadline: $this->nullableDateTime(
                $row['current_question_deadline'],
            ),
            currentQuestionClosedAt: $this->nullableDateTime(
                $row['current_question_closed_at'],
            ),
            joinDeadline: $this->nullableDateTime($row['join_deadline']),
            startedAt: $this->nullableDateTime($row['started_at']),
            endedAt: $this->nullableDateTime($row['ended_at']),
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            questionCount: (int) $row['question_count'],
            participantCount: (int) $row['participant_count'],
        );
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
