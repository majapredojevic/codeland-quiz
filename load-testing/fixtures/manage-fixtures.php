<?php

declare(strict_types=1);

use CodeLandQuiz\Auth\AuditLogService;
use CodeLandQuiz\Auth\BcryptPasswordHasher;
use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\DTO\CreateQuestionDTO;
use CodeLandQuiz\DTO\CreateQuizDTO;
use CodeLandQuiz\DTO\CreateStudentDTO;
use CodeLandQuiz\DTO\QuestionOptionInputDTO;
use CodeLandQuiz\Model\NewUser;
use CodeLandQuiz\Model\QuestionType;
use CodeLandQuiz\Model\UserRole;
use CodeLandQuiz\Question\QuestionContentValidator;
use CodeLandQuiz\Question\QuestionService;
use CodeLandQuiz\QuestionImage\QuestionImageService;
use CodeLandQuiz\QuestionImage\QuestionImageStorage;
use CodeLandQuiz\QuestionImage\Exception\QuestionImageNotFoundException;
use CodeLandQuiz\Quiz\QuizService;
use CodeLandQuiz\QuizSession\ClosedQuestionResultAssembler;
use CodeLandQuiz\QuizSession\FinalQuizSessionResultAssembler;
use CodeLandQuiz\QuizSession\PublicSessionQuestionMapper;
use CodeLandQuiz\QuizSession\QuizSessionService;
use CodeLandQuiz\QuizSession\SecureGamePinGenerator;
use CodeLandQuiz\Repository\MySqlAuditLogRepository;
use CodeLandQuiz\Repository\MySqlParticipantAnswerRepository;
use CodeLandQuiz\Repository\MySqlQuestionImageReferenceRepository;
use CodeLandQuiz\Repository\MySqlQuestionRepository;
use CodeLandQuiz\Repository\MySqlQuizRepository;
use CodeLandQuiz\Repository\MySqlQuizSessionRepository;
use CodeLandQuiz\Repository\MySqlQuizSessionResultRepository;
use CodeLandQuiz\Repository\MySqlSessionParticipantRepository;
use CodeLandQuiz\Repository\MySqlSessionQuestionRepository;
use CodeLandQuiz\Repository\MySqlStudentRepository;
use CodeLandQuiz\Repository\MySqlTopicRepository;
use CodeLandQuiz\Repository\MySqlUserRepository;
use CodeLandQuiz\Student\StudentService;
use CodeLandQuiz\Support\Database;
use CodeLandQuiz\Support\Environment;
use CodeLandQuiz\Support\PdoTransactionManager;

require '/var/www/backend/vendor/autoload.php';

const MANIFEST_SCHEMA_VERSION = 1;
const AVATAR_KEYS = [
    'koda-blue',
    'koda-green',
    'koda-orange',
    'koda-pink',
    'koda-purple',
    'koda-red',
    'koda-turquoise',
    'koda-yellow',
];

/**
 * @return array<string, string>
 */
function arguments(array $argv): array
{
    $result = [];

    foreach (array_slice($argv, 2) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException(sprintf('Invalid argument: %s', $argument));
        }

        [$name, $value] = explode('=', substr($argument, 2), 2);
        $result[$name] = $value;
    }

    return $result;
}

function required(array $arguments, string $name): string
{
    $value = $arguments[$name] ?? null;

    if (!is_string($value) || $value === '') {
        throw new InvalidArgumentException(sprintf('Missing --%s.', $name));
    }

    return $value;
}

function positiveInteger(array $arguments, string $name): int
{
    $value = filter_var(
        required($arguments, $name),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]],
    );

    if (!is_int($value)) {
        throw new InvalidArgumentException(sprintf('--%s must be a positive integer.', $name));
    }

    return $value;
}

function percentage(array $arguments, string $name): float
{
    $raw = required($arguments, $name);

    if (!is_numeric($raw)) {
        throw new InvalidArgumentException(sprintf('--%s must be numeric.', $name));
    }

    $value = (float) $raw;

    if ($value < 0 || $value > 100) {
        throw new InvalidArgumentException(sprintf('--%s must be between 0 and 100.', $name));
    }

    return $value;
}

function ratio(array $arguments, string $name): float
{
    $raw = required($arguments, $name);

    if (!is_numeric($raw)) {
        throw new InvalidArgumentException(sprintf('--%s must be numeric.', $name));
    }

    $value = (float) $raw;

    if ($value < 0 || $value > 1) {
        throw new InvalidArgumentException(sprintf('--%s must be between 0 and 1.', $name));
    }

    return $value;
}

/**
 * @return array<string, mixed>
 */
function readJson(string $path): array
{
    $contents = file_get_contents($path);

    if (!is_string($contents)) {
        throw new RuntimeException(sprintf('Could not read JSON file: %s', $path));
    }

    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException(sprintf('JSON root is not an object: %s', $path));
    }

    return $decoded;
}

function writeJson(string $path, array $value): void
{
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Could not create directory: %s', $directory));
    }

    $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
    $json = json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    ) . PHP_EOL;

    if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
        @unlink($temporary);
        throw new RuntimeException(sprintf('Could not write JSON file: %s', $path));
    }

    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException(sprintf('Could not publish JSON file: %s', $path));
    }

    @chmod($path, 0660);
}

/**
 * @return int[]
 */
function balancedDistribution(int $students, int $sessions): array
{
    if ($sessions > $students) {
        throw new InvalidArgumentException('Session count cannot exceed Student count.');
    }

    $minimum = intdiv($students, $sessions);
    $remainder = $students % $sessions;
    $distribution = [];

    for ($slot = 0; $slot < $sessions; $slot++) {
        $distribution[] = $minimum + ($slot < $remainder ? 1 : 0);
    }

    if (array_sum($distribution) !== $students || max($distribution) - min($distribution) > 1) {
        throw new RuntimeException('Student distribution is not balanced and exact.');
    }

    return $distribution;
}

/**
 * @return int[]
 */
function selectedIndexes(int $total, int $selected, int $seed, string $purpose): array
{
    $scores = [];

    for ($index = 0; $index < $total; $index++) {
        $scores[$index] = hash('sha256', sprintf('%d|%d|%s', $seed, $index, $purpose));
    }

    uasort($scores, static fn(string $left, string $right): int => $left <=> $right);
    $indexes = array_slice(array_keys($scores), 0, $selected);
    sort($indexes, SORT_NUMERIC);

    return $indexes;
}

/**
 * @return array<int, array<string, mixed>>
 */
function questionDefinitions(): array
{
    return [
        [
            'text' => 'PHP is executed by the CodeLand Quiz backend.',
            'type' => QuestionType::TRUE_FALSE,
            'options' => [['Tačno', true], ['Netačno', false]],
            'points' => 1000,
            'image' => false,
        ],
        [
            'text' => 'Which protocol carries live Player events?',
            'type' => QuestionType::SINGLE_CHOICE,
            'options' => [['FTP', false], ['WebSocket', true], ['SMTP', false], ['SSH', false]],
            'points' => 1100,
            'image' => false,
        ],
        [
            'text' => 'Select the Question types supported by this fixture.',
            'type' => QuestionType::MULTIPLE_CHOICE,
            'options' => [
                ['TRUE_FALSE', true],
                ['FREE_TEXT', false],
                ['SINGLE_CHOICE', true],
                ['ESSAY', false],
            ],
            'points' => 1200,
            'image' => false,
        ],
        [
            'text' => 'A heartbeat acknowledgement submits an answer.',
            'type' => QuestionType::TRUE_FALSE,
            'options' => [['Tačno', false], ['Netačno', true]],
            'points' => 900,
            'image' => false,
        ],
        [
            'text' => 'Which component terminates TLS in the production-like path?',
            'type' => QuestionType::SINGLE_CHOICE,
            'options' => [['Angular CLI', false], ['MySQL', false], ['OpenSwoole directly', false], ['Nginx', true]],
            'points' => 1300,
            'image' => true,
        ],
        [
            'text' => 'Select the layers exercised by the load-test path.',
            'type' => QuestionType::MULTIPLE_CHOICE,
            'options' => [['Nginx', true], ['OpenSwoole', true], ['MySQL', true], ['phpMyAdmin', false]],
            'points' => 1400,
            'image' => false,
        ],
    ];
}

/**
 * @return array<string, object>
 */
function services(Database $database, AppConfig $config): array
{
    $transactions = new PdoTransactionManager($database);
    $audits = new AuditLogService(new MySqlAuditLogRepository($database));
    $quizzes = new MySqlQuizRepository($database);
    $questions = new MySqlQuestionRepository($database);
    $topics = new MySqlTopicRepository($database);
    $storage = new QuestionImageStorage($config);
    $sessionResults = new MySqlQuizSessionResultRepository($database);

    return [
        'users' => new MySqlUserRepository($database),
        'students' => new StudentService(
            new MySqlStudentRepository($database),
            $audits,
            $transactions,
        ),
        'quizzes' => new QuizService(
            $quizzes,
            $topics,
            $questions,
            new QuestionContentValidator(),
            $audits,
            $transactions,
        ),
        'questions' => new QuestionService(
            $questions,
            $quizzes,
            $storage,
            new QuestionContentValidator(),
            $audits,
            $transactions,
        ),
        'images' => new QuestionImageService(
            $quizzes,
            new MySqlQuestionImageReferenceRepository($database),
            $storage,
            $transactions,
        ),
        'imageStorage' => $storage,
        'sessions' => new QuizSessionService(
            $quizzes,
            $questions,
            new MySqlQuizSessionRepository($database),
            new MySqlSessionQuestionRepository($database),
            new PublicSessionQuestionMapper(),
            new QuestionContentValidator(),
            new SecureGamePinGenerator(),
            $audits,
            $transactions,
            $sessionResults,
            new ClosedQuestionResultAssembler($sessionResults),
            new FinalQuizSessionResultAssembler($sessionResults),
            new MySqlSessionParticipantRepository($database),
        ),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function snapshotQuestions(PDO $connection, int $sessionId): array
{
    $statement = $connection->prepare(<<<SQL
SELECT
    sq.id AS session_question_id,
    sq.source_question_id,
    sq.question_type,
    sq.question_order,
    sq.image_path,
    sq.time_limit_seconds,
    sq.max_points,
    sqo.id AS option_id,
    sqo.is_correct,
    sqo.option_order
FROM session_questions sq
INNER JOIN session_question_options sqo
    ON sqo.session_question_id = sq.id
WHERE sq.session_id = :session_id
ORDER BY sq.question_order ASC, sqo.option_order ASC
SQL);
    $statement->execute(['session_id' => $sessionId]);
    $questions = [];

    while (($row = $statement->fetch()) !== false) {
        $questionId = (int) $row['session_question_id'];

        if (!isset($questions[$questionId])) {
            $questions[$questionId] = [
                'sessionQuestionId' => $questionId,
                'sourceQuestionId' => (int) $row['source_question_id'],
                'questionType' => (string) $row['question_type'],
                'questionOrder' => (int) $row['question_order'],
                'imagePath' => $row['image_path'] === null ? null : (string) $row['image_path'],
                'timeLimitSeconds' => (int) $row['time_limit_seconds'],
                'maxPoints' => (int) $row['max_points'],
                'optionIds' => [],
                'correctOptionIds' => [],
            ];
        }

        $optionId = (int) $row['option_id'];
        $questions[$questionId]['optionIds'][] = $optionId;

        if ((bool) (int) $row['is_correct']) {
            $questions[$questionId]['correctOptionIds'][] = $optionId;
        }
    }

    return array_values($questions);
}

function createFixtureImage(QuestionImageService $images, int $quizId): object
{
    $base64 = trim((string) file_get_contents(__DIR__ . '/question-image.png.base64'));
    $contents = base64_decode($base64, true);

    if (!is_string($contents) || $contents === '') {
        throw new RuntimeException('Fixture Question image is invalid.');
    }

    $temporaryPath = tempnam(sys_get_temp_dir(), 'clq-load-image-');

    if (!is_string($temporaryPath) || file_put_contents($temporaryPath, $contents) !== strlen($contents)) {
        throw new RuntimeException('Fixture Question image temporary file could not be created.');
    }

    try {
        return $images->upload($quizId, [
            'name' => 'load-test-question.png',
            'tmp_name' => $temporaryPath,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($contents),
        ]);
    } finally {
        @unlink($temporaryPath);
    }
}

function provision(array $arguments, Database $database, AppConfig $config): void
{
    $manifestPath = required($arguments, 'manifest');
    $credentialsPath = required($arguments, 'credentials');
    $students = positiveInteger($arguments, 'students');
    $sessionCount = positiveInteger($arguments, 'sessions');
    $runId = required($arguments, 'run-id');
    $seed = positiveInteger($arguments, 'seed');
    $mode = strtoupper(required($arguments, 'mode'));
    $registeredPercent = percentage($arguments, 'registered-percent');
    $reconnectPercent = percentage($arguments, 'reconnect-percent');
    $correctRatio = ratio($arguments, 'correct-ratio');
    $burstMajorityRatio = ratio($arguments, 'burst-majority-ratio');

    if (preg_match('/^[a-f0-9]{24}$/D', $runId) !== 1) {
        throw new InvalidArgumentException('runId must be 24 lowercase hexadecimal characters.');
    }
    if (!in_array($mode, ['CLASSROOM', 'BURST'], true)) {
        throw new InvalidArgumentException('Mode must be CLASSROOM or BURST.');
    }

    $distribution = balancedDistribution($students, $sessionCount);
    $registeredCount = (int) round($students * $registeredPercent / 100, 0, PHP_ROUND_HALF_UP);
    $reconnectCount = $reconnectPercent <= 0
        ? 0
        : max(1, (int) round($students * $reconnectPercent / 100, 0, PHP_ROUND_HALF_UP));
    $registeredIndexes = array_flip(selectedIndexes($students, $registeredCount, $seed, 'registered'));
    $reconnectIndexes = array_flip(selectedIndexes($students, min($students, $reconnectCount), $seed, 'reconnect'));
    $prefix = substr($runId, 0, 10);
    $questionCount = count(questionDefinitions());
    $questionOpenMs = $mode === 'BURST' ? 4200 : 7000;
    $manifest = [
        'schemaVersion' => MANIFEST_SCHEMA_VERSION,
        'runId' => $runId,
        'requestedStudentCount' => $students,
        'sessionCount' => $sessionCount,
        'studentDistribution' => $distribution,
        'mode' => $mode,
        'seed' => $seed,
        'questionCount' => $questionCount,
        'createdAt' => gmdate(DATE_ATOM),
        'configuration' => [
            'targetUrl' => required($arguments, 'target-url'),
            'localSelfSignedCertificate' => required($arguments, 'local-self-signed') === 'true',
            'executor' => 'per-vu-iterations',
            'iterationsPerVu' => 1,
            'registeredParticipantPercent' => $registeredPercent,
            'registeredParticipantCount' => $registeredCount,
            'guestParticipantCount' => $students - $registeredCount,
            'correctAnswerRatio' => $correctRatio,
            'reconnectPercent' => $reconnectPercent,
            'reconnectPlayerCount' => min($students, $reconnectCount),
            'reconnectAfterQuestion' => 2,
            'reconnectDelayMs' => 800,
            'teacherLoginStaggerMs' => 100,
            'scheduleLeadInMs' => 15000,
            'csrfCookieName' => 'codeland_csrf',
            'timing' => [
                'questionOpenMs' => $questionOpenMs,
                'betweenQuestionsMs' => 1500,
                'classroomMinimumMs' => 1000,
                'classroomMaximumMs' => 5500,
                'burstMajorityRatio' => $burstMajorityRatio,
                'burstMinimumMs' => 250,
                'burstMaximumMs' => 1800,
                'burstTailMinimumMs' => 1800,
                'burstTailMaximumMs' => 3200,
            ],
        ],
        'teacherIds' => [],
        'quizIds' => [],
        'sessionIds' => [],
        'registeredStudentIds' => [],
        'questionIds' => [],
        'sessions' => [],
        'players' => [],
        'resources' => [
            'images' => [],
            'participantIds' => [],
            'answerIds' => [],
            'auditLogIds' => [],
            'loginAttemptIds' => [],
        ],
        'provisioningStatus' => 'IN_PROGRESS',
    ];
    $credentials = [
        'schemaVersion' => 1,
        'runId' => $runId,
        'teachers' => [],
    ];
    writeJson($manifestPath, $manifest);
    writeJson($credentialsPath, $credentials);
    $service = services($database, $config);
    $passwordHasher = new BcryptPasswordHasher();

    try {
        for ($slotIndex = 0; $slotIndex < $sessionCount; $slotIndex++) {
            $slot = $slotIndex + 1;
            $email = sprintf('lt-%s-teacher%02d@example.test', $prefix, $slot);
            $password = bin2hex(random_bytes(16)) . 'Aa1!';
            $teacherId = $service['users']->create(new NewUser(
                name: sprintf('Load Test Teacher %02d', $slot),
                email: $email,
                passwordHash: $passwordHasher->hash($password),
                mustChangePassword: false,
                role: UserRole::TEACHER,
            ));
            $manifest['teacherIds'][] = $teacherId;
            $credentials['teachers'][] = [
                'sessionSlot' => $slot,
                'email' => $email,
                'password' => $password,
            ];
            writeJson($manifestPath, $manifest);
            writeJson($credentialsPath, $credentials);
        }

        $studentIdByPlayer = [];

        foreach (array_keys($registeredIndexes) as $playerIndex) {
            $student = $service['students']->createStudent(
                actorUserId: $manifest['teacherIds'][0],
                dto: new CreateStudentDTO(
                    firstName: 'Load',
                    lastName: sprintf('Student %03d', $playerIndex + 1),
                    username: sprintf('lt_%s_s%03d', $prefix, $playerIndex + 1),
                ),
            );
            $studentIdByPlayer[$playerIndex] = $student->id;
            $manifest['registeredStudentIds'][] = $student->id;
            writeJson($manifestPath, $manifest);
        }

        $playerIndex = 0;

        for ($slotIndex = 0; $slotIndex < $sessionCount; $slotIndex++) {
            $slot = $slotIndex + 1;
            $teacherId = $manifest['teacherIds'][$slotIndex];
            $quiz = $service['quizzes']->createQuiz(
                $teacherId,
                new CreateQuizDTO(
                    title: sprintf('LT %s Session %02d', $prefix, $slot),
                    version: 1,
                    description: 'Temporary synthetic Phase 4A load-test fixture.',
                    topicId: null,
                ),
            );
            $manifest['quizIds'][] = $quiz->id;
            writeJson($manifestPath, $manifest);

            $image = createFixtureImage($service['images'], $quiz->id);
            $manifest['resources']['images'][] = [
                'quizId' => $quiz->id,
                'fileName' => $image->fileName,
                'path' => $image->path,
            ];
            writeJson($manifestPath, $manifest);
            $sourceQuestionIds = [];

            foreach (questionDefinitions() as $definition) {
                $question = $service['questions']->createQuestion(
                    $teacherId,
                    $quiz->id,
                    new CreateQuestionDTO(
                        questionText: $definition['text'],
                        questionType: $definition['type'],
                        imagePath: $definition['image'] ? $image->path : null,
                        timeLimitSeconds: 30,
                        maxPoints: $definition['points'],
                        options: array_map(
                            static fn(array $option): QuestionOptionInputDTO =>
                                new QuestionOptionInputDTO($option[0], $option[1]),
                            $definition['options'],
                        ),
                    ),
                );
                $sourceQuestionIds[] = $question->id;
                $manifest['questionIds'][] = $question->id;
                writeJson($manifestPath, $manifest);
            }

            $service['quizzes']->activateQuiz($teacherId, $quiz->id);
            $session = $service['sessions']->createSession($teacherId, $quiz->id);
            $manifest['sessionIds'][] = $session->id;
            $sessionManifest = [
                'sessionSlot' => $slot,
                'teacherId' => $teacherId,
                'quizId' => $quiz->id,
                'sourceQuestionIds' => $sourceQuestionIds,
                'sessionId' => $session->id,
                'gamePin' => $session->gamePin,
                'expectedStudentCount' => $distribution[$slotIndex],
                'questions' => snapshotQuestions($database->getConnection(), $session->id),
            ];
            $manifest['sessions'][] = $sessionManifest;
            writeJson($manifestPath, $manifest);

            for ($inSession = 0; $inSession < $distribution[$slotIndex]; $inSession++) {
                $isRegistered = isset($registeredIndexes[$playerIndex]);
                $manifest['players'][] = [
                    'playerIndex' => $playerIndex,
                    'sessionSlot' => $slot,
                    'participantType' => $isRegistered ? 'REGISTERED' : 'GUEST',
                    'studentId' => $isRegistered ? $studentIdByPlayer[$playerIndex] : null,
                    'username' => $isRegistered
                        ? sprintf('lt_%s_s%03d', $prefix, $playerIndex + 1)
                        : null,
                    'nickname' => sprintf('LT%sP%03d', substr($prefix, 0, 5), $playerIndex + 1),
                    'avatarKey' => AVATAR_KEYS[$playerIndex % count(AVATAR_KEYS)],
                    'reconnect' => isset($reconnectIndexes[$playerIndex]),
                ];
                $playerIndex++;
            }
            writeJson($manifestPath, $manifest);
        }

        if ($playerIndex !== $students || count($manifest['players']) !== $students) {
            throw new RuntimeException('Fixture Player allocation is not exact.');
        }

        $manifest['provisioningStatus'] = 'COMPLETE';
        $manifest['provisionedAt'] = gmdate(DATE_ATOM);
        writeJson($manifestPath, $manifest);
        if (!chmod($credentialsPath, 0644)) {
            throw new RuntimeException('Ephemeral credential file could not be made readable by the isolated k6 container.');
        }
        echo sprintf("Provisioned run %s with %d Players across %d Sessions.\n", $runId, $students, $sessionCount);
    } catch (Throwable $throwable) {
        $manifest['provisioningStatus'] = 'FAILED';
        $manifest['provisioningErrorClass'] = $throwable::class;
        writeJson($manifestPath, $manifest);
        throw $throwable;
    }
}

/**
 * @return int[]
 */
function integerIds(array $values): array
{
    $ids = [];

    foreach ($values as $value) {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new RuntimeException('Manifest contains a non-integer resource ID.');
        }
        $id = (int) $value;
        if ($id < 1) throw new RuntimeException('Manifest contains a non-positive resource ID.');
        $ids[$id] = $id;
    }

    return array_values($ids);
}

/**
 * @return array{0: string, 1: array<string, int>}
 */
function inClause(array $ids, string $prefix): array
{
    $parameters = [];
    $placeholders = [];

    foreach (integerIds($ids) as $index => $id) {
        $name = sprintf('%s_%d', $prefix, $index);
        $placeholders[] = ':' . $name;
        $parameters[$name] = $id;
    }

    if ($placeholders === []) {
        throw new RuntimeException('Refusing to construct an empty resource-ID predicate.');
    }

    return [implode(', ', $placeholders), $parameters];
}

/**
 * @return int[]
 */
function selectIds(PDO $connection, string $sql, array $parameters): array
{
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

function finalizeManifest(string $manifestPath, Database $database): array
{
    $manifest = readJson($manifestPath);
    $sessionIds = integerIds($manifest['sessionIds'] ?? []);
    $teacherIds = integerIds($manifest['teacherIds'] ?? []);
    $connection = $database->getConnection();
    $resources = $manifest['resources'] ?? [];

    if ($sessionIds !== []) {
        [$sessionClause, $sessionParameters] = inClause($sessionIds, 'session');
        $resources['participantIds'] = selectIds(
            $connection,
            "SELECT id FROM session_participants WHERE session_id IN ($sessionClause) ORDER BY id",
            $sessionParameters,
        );
        $resources['answerIds'] = selectIds(
            $connection,
            <<<SQL
SELECT pa.id
FROM participant_answers pa
INNER JOIN session_participants sp ON sp.id = pa.session_participant_id
WHERE sp.session_id IN ($sessionClause)
ORDER BY pa.id
SQL,
            $sessionParameters,
        );
    }

    if ($teacherIds !== []) {
        [$teacherClause, $teacherParameters] = inClause($teacherIds, 'teacher');
        $resources['auditLogIds'] = selectIds(
            $connection,
            "SELECT id FROM audit_logs WHERE user_id IN ($teacherClause) ORDER BY id",
            $teacherParameters,
        );
    }

    if ($teacherIds !== []) {
        [$teacherClause, $teacherParameters] = inClause($teacherIds, 'login_teacher');
        $resources['loginAttemptIds'] = selectIds(
            $connection,
            "SELECT la.id FROM login_attempts la INNER JOIN users u ON u.email = la.email WHERE u.id IN ($teacherClause) ORDER BY la.id",
            $teacherParameters,
        );
    }

    $manifest['resources'] = $resources;
    $manifest['finalizedAt'] = gmdate(DATE_ATOM);
    writeJson($manifestPath, $manifest);

    return $manifest;
}

function deleteExactIds(PDO $connection, string $table, array $ids, string $prefix): int
{
    $ids = integerIds($ids);
    if ($ids === []) return 0;
    [$clause, $parameters] = inClause($ids, $prefix);
    $statement = $connection->prepare("DELETE FROM $table WHERE id IN ($clause)");
    $statement->execute($parameters);
    return $statement->rowCount();
}

function cleanup(string $manifestPath, Database $database, AppConfig $config): void
{
    $manifest = finalizeManifest($manifestPath, $database);
    $connection = $database->getConnection();
    $resources = $manifest['resources'];
    $deleted = [];
    $connection->beginTransaction();

    try {
        $deleted['auditLogs'] = deleteExactIds($connection, 'audit_logs', $resources['auditLogIds'] ?? [], 'audit');
        $deleted['loginAttempts'] = deleteExactIds($connection, 'login_attempts', $resources['loginAttemptIds'] ?? [], 'login');
        $deleted['sessions'] = deleteExactIds($connection, 'quiz_sessions', $manifest['sessionIds'] ?? [], 'session_delete');
        $deleted['quizzes'] = deleteExactIds($connection, 'quizzes', $manifest['quizIds'] ?? [], 'quiz_delete');
        $deleted['students'] = deleteExactIds($connection, 'students', $manifest['registeredStudentIds'] ?? [], 'student_delete');
        $deleted['teachers'] = deleteExactIds($connection, 'users', $manifest['teacherIds'] ?? [], 'teacher_delete');
        $connection->commit();
    } catch (Throwable $throwable) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $throwable;
    }

    $storage = new QuestionImageStorage($config);
    $deletedImages = 0;

    foreach ($resources['images'] ?? [] as $image) {
        try {
            $storage->delete((int) $image['quizId'], (string) $image['fileName']);
            $deletedImages++;
        } catch (QuestionImageNotFoundException) {
            // An earlier recovery attempt may already have removed this exact file.
        }
    }
    $deleted['images'] = $deletedImages;
    $manifest['cleanup'] = [
        'completedAt' => gmdate(DATE_ATOM),
        'deleted' => $deleted,
    ];
    writeJson($manifestPath, $manifest);
    echo sprintf("Cleaned exact resources for run %s.\n", $manifest['runId']);
}

function countExactIds(PDO $connection, string $table, array $ids, string $prefix): int
{
    $ids = integerIds($ids);
    if ($ids === []) return 0;
    [$clause, $parameters] = inClause($ids, $prefix);
    $statement = $connection->prepare("SELECT COUNT(*) FROM $table WHERE id IN ($clause)");
    $statement->execute($parameters);
    return (int) $statement->fetchColumn();
}

function verifyClean(string $manifestPath, string $outputPath, Database $database, AppConfig $config): void
{
    $manifest = readJson($manifestPath);
    $connection = $database->getConnection();
    $resources = $manifest['resources'] ?? [];
    $remaining = [
        'teachers' => countExactIds($connection, 'users', $manifest['teacherIds'] ?? [], 'vc_teacher'),
        'students' => countExactIds($connection, 'students', $manifest['registeredStudentIds'] ?? [], 'vc_student'),
        'quizzes' => countExactIds($connection, 'quizzes', $manifest['quizIds'] ?? [], 'vc_quiz'),
        'questions' => countExactIds($connection, 'questions', $manifest['questionIds'] ?? [], 'vc_question'),
        'sessions' => countExactIds($connection, 'quiz_sessions', $manifest['sessionIds'] ?? [], 'vc_session'),
        'participants' => countExactIds($connection, 'session_participants', $resources['participantIds'] ?? [], 'vc_participant'),
        'answers' => countExactIds($connection, 'participant_answers', $resources['answerIds'] ?? [], 'vc_answer'),
        'auditLogs' => countExactIds($connection, 'audit_logs', $resources['auditLogIds'] ?? [], 'vc_audit'),
        'loginAttempts' => countExactIds($connection, 'login_attempts', $resources['loginAttemptIds'] ?? [], 'vc_login'),
    ];
    $remainingImages = 0;
    $storage = new QuestionImageStorage($config);

    foreach ($resources['images'] ?? [] as $image) {
        try {
            $storage->publicFile((int) $image['quizId'], (string) $image['fileName']);
            $remainingImages++;
        } catch (QuestionImageNotFoundException) {
            // The exact managed file is absent, as expected.
        }
    }
    $remaining['images'] = $remainingImages;
    $result = [
        'runId' => $manifest['runId'],
        'checkedAt' => gmdate(DATE_ATOM),
        'passed' => array_sum($remaining) === 0,
        'remaining' => $remaining,
    ];
    writeJson($outputPath, $result);

    if (!$result['passed']) {
        throw new RuntimeException('Exact load-test fixture cleanup verification failed.');
    }

    echo sprintf("Cleanup verification passed for run %s.\n", $manifest['runId']);
}

$command = $argv[1] ?? '';
$arguments = arguments($argv);
$environment = new Environment('/var/www/backend');
$database = new Database($environment);
$config = new AppConfig($environment);

try {
    switch ($command) {
        case 'provision':
            provision($arguments, $database, $config);
            break;
        case 'finalize':
            finalizeManifest(required($arguments, 'manifest'), $database);
            break;
        case 'cleanup':
            cleanup(required($arguments, 'manifest'), $database, $config);
            break;
        case 'verify-clean':
            verifyClean(
            required($arguments, 'manifest'),
            required($arguments, 'output'),
            $database,
            $config,
            );
            break;
        default:
            throw new InvalidArgumentException(
                'Command must be provision, finalize, cleanup, or verify-clean.',
            );
    }
} catch (Throwable $throwable) {
    fwrite(STDERR, sprintf("%s: %s\n", $throwable::class, $throwable->getMessage()));
    exit(1);
}
