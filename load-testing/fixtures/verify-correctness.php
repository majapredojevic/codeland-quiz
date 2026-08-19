<?php

declare(strict_types=1);

use CodeLandQuiz\Game\AnswerScoreCalculator;
use CodeLandQuiz\Repository\MySqlQuizSessionResultRepository;
use CodeLandQuiz\Support\Database;
use CodeLandQuiz\Support\Environment;

require '/var/www/backend/vendor/autoload.php';

/**
 * @return array<string, string>
 */
function verifierArguments(array $argv): array
{
    $arguments = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException(sprintf('Invalid argument: %s', $argument));
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        $arguments[$name] = $value;
    }

    return $arguments;
}

function verifierRequired(array $arguments, string $name): string
{
    $value = $arguments[$name] ?? null;
    if (!is_string($value) || $value === '') {
        throw new InvalidArgumentException(sprintf('Missing --%s.', $name));
    }
    return $value;
}

/**
 * @return array<string, mixed>
 */
function verifierReadJson(string $path): array
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) throw new RuntimeException('Manifest could not be read.');
    $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_is_list($value)) {
        throw new RuntimeException('Manifest root must be an object.');
    }
    return $value;
}

function verifierWriteJson(string $path, array $value): void
{
    $json = json_encode(
        $value,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    ) . PHP_EOL;
    if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) {
        throw new RuntimeException('Correctness result could not be written.');
    }
}

/**
 * @return int[]
 */
function verifierIds(array $values): array
{
    $ids = [];
    foreach ($values as $value) {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new RuntimeException('Manifest resource ID is invalid.');
        }
        $id = (int) $value;
        if ($id < 1) throw new RuntimeException('Manifest resource ID is not positive.');
        $ids[$id] = $id;
    }
    return array_values($ids);
}

/**
 * @return array{0: string, 1: array<string, int>}
 */
function verifierIn(array $ids, string $prefix): array
{
    $placeholders = [];
    $parameters = [];
    foreach (verifierIds($ids) as $index => $id) {
        $name = sprintf('%s_%d', $prefix, $index);
        $placeholders[] = ':' . $name;
        $parameters[$name] = $id;
    }
    if ($placeholders === []) throw new RuntimeException('Manifest contains no Session IDs.');
    return [implode(', ', $placeholders), $parameters];
}

/**
 * @return array<int, array<string, mixed>>
 */
function fetchAll(PDO $connection, string $sql, array $parameters): array
{
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll();
}

/**
 * @param array<int, array<string, mixed>> $checks
 */
function recordCheck(array &$checks, string $name, bool $passed, array $details = []): void
{
    $checks[] = ['name' => $name, 'passed' => $passed, 'details' => $details];
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return int[]
 */
function independentlyOrderedParticipantIds(array $rows): array
{
    usort($rows, static function (array $left, array $right): int {
        foreach ([
            ['total_score', true],
            ['correct_answer_count', true],
            ['answer_count', true],
            ['total_response_time_ms', false],
        ] as [$field, $descending]) {
            $comparison = (int) $left[$field] <=> (int) $right[$field];
            if ($comparison !== 0) return $descending ? -$comparison : $comparison;
        }
        $comparison = strcmp((string) $left['joined_at'], (string) $right['joined_at']);
        if ($comparison !== 0) return $comparison;
        return (int) $left['id'] <=> (int) $right['id'];
    });
    return array_map(static fn(array $row): int => (int) $row['id'], $rows);
}

$arguments = verifierArguments($argv);
$manifestPath = verifierRequired($arguments, 'manifest');
$outputPath = verifierRequired($arguments, 'output');
$manifest = verifierReadJson($manifestPath);
$environment = new Environment('/var/www/backend');
$database = new Database($environment);
$connection = $database->getConnection();
$checks = [];
$sessionIds = verifierIds($manifest['sessionIds'] ?? []);
[$sessionClause, $sessionParameters] = verifierIn($sessionIds, 'session');
$expectedSessions = [];

foreach ($manifest['sessions'] ?? [] as $session) {
    $expectedSessions[(int) $session['sessionId']] = $session;
}

$sessionRows = fetchAll($connection, <<<SQL
SELECT
    qs.id,
    qs.quiz_id,
    qs.host_user_id,
    qs.status,
    qs.current_question_order,
    qs.current_question_closed_at,
    qs.ended_at,
    (SELECT COUNT(*) FROM session_questions sq WHERE sq.session_id = qs.id) AS question_count
FROM quiz_sessions qs
WHERE qs.id IN ($sessionClause)
ORDER BY qs.id
SQL, $sessionParameters);
recordCheck(
    $checks,
    'all expected Sessions exist and only manifest Session IDs were inspected',
    count($sessionRows) === count($sessionIds),
    ['expected' => count($sessionIds), 'actual' => count($sessionRows)],
);

$sessionOwnershipOk = true;
$finishedLifecycleOk = true;
foreach ($sessionRows as $row) {
    $expected = $expectedSessions[(int) $row['id']] ?? null;
    $sessionOwnershipOk = $sessionOwnershipOk
        && $expected !== null
        && (int) $row['quiz_id'] === (int) $expected['quizId']
        && (int) $row['host_user_id'] === (int) $expected['teacherId']
        && (int) $row['question_count'] === (int) $manifest['questionCount'];
    $finishedLifecycleOk = $finishedLifecycleOk
        && $row['status'] === 'FINISHED'
        && (int) $row['current_question_order'] === (int) $manifest['questionCount']
        && $row['current_question_closed_at'] !== null
        && $row['ended_at'] !== null;
}
recordCheck($checks, 'Sessions belong to their exact fixture Quiz and Teacher', $sessionOwnershipOk);
recordCheck($checks, 'Sessions reached the expected finished lifecycle state', $finishedLifecycleOk);

$questionRows = fetchAll($connection, <<<SQL
SELECT
    sq.id,
    sq.session_id,
    sq.source_question_id,
    sq.question_type,
    sq.question_order,
    sq.time_limit_seconds,
    sq.max_points,
    sqo.id AS option_id,
    sqo.is_correct
FROM session_questions sq
INNER JOIN session_question_options sqo ON sqo.session_question_id = sq.id
WHERE sq.session_id IN ($sessionClause)
ORDER BY sq.session_id, sq.question_order, sqo.option_order
SQL, $sessionParameters);
$questions = [];
foreach ($questionRows as $row) {
    $questionId = (int) $row['id'];
    if (!isset($questions[$questionId])) {
        $questions[$questionId] = [
            'id' => $questionId,
            'session_id' => (int) $row['session_id'],
            'source_question_id' => (int) $row['source_question_id'],
            'question_type' => (string) $row['question_type'],
            'question_order' => (int) $row['question_order'],
            'time_limit_seconds' => (int) $row['time_limit_seconds'],
            'max_points' => (int) $row['max_points'],
            'option_ids' => [],
            'correct_option_ids' => [],
        ];
    }
    $optionId = (int) $row['option_id'];
    $questions[$questionId]['option_ids'][] = $optionId;
    if ((bool) (int) $row['is_correct']) $questions[$questionId]['correct_option_ids'][] = $optionId;
}
$snapshotAssociationOk = true;
foreach ($expectedSessions as $sessionId => $expectedSession) {
    foreach ($expectedSession['questions'] as $expectedQuestion) {
        $actual = $questions[(int) $expectedQuestion['sessionQuestionId']] ?? null;
        $actualOptionIds = $actual['option_ids'] ?? [];
        $actualCorrectOptionIds = $actual['correct_option_ids'] ?? [];
        $expectedOptionIds = array_map('intval', $expectedQuestion['optionIds']);
        $expectedCorrectOptionIds = array_map('intval', $expectedQuestion['correctOptionIds']);
        sort($actualOptionIds, SORT_NUMERIC);
        sort($actualCorrectOptionIds, SORT_NUMERIC);
        sort($expectedOptionIds, SORT_NUMERIC);
        sort($expectedCorrectOptionIds, SORT_NUMERIC);
        $snapshotAssociationOk = $snapshotAssociationOk
            && $actual !== null
            && $actual['session_id'] === $sessionId
            && $actual['source_question_id'] === (int) $expectedQuestion['sourceQuestionId']
            && $actual['question_type'] === $expectedQuestion['questionType']
            && $actual['question_order'] === (int) $expectedQuestion['questionOrder']
            && $actual['time_limit_seconds'] === (int) $expectedQuestion['timeLimitSeconds']
            && $actual['max_points'] === (int) $expectedQuestion['maxPoints']
            && $actualOptionIds === $expectedOptionIds
            && $actualCorrectOptionIds === $expectedCorrectOptionIds;
    }
}
recordCheck($checks, 'Session Question snapshots match source, order, options, and Session', $snapshotAssociationOk);

$participantRows = fetchAll($connection, <<<SQL
SELECT
    sp.id,
    sp.session_id,
    sp.participant_type,
    sp.student_id,
    sp.nickname,
    sp.total_score,
    sp.is_removed,
    sp.joined_at
FROM session_participants sp
WHERE sp.session_id IN ($sessionClause)
ORDER BY sp.session_id, sp.id
SQL, $sessionParameters);
$expectedPlayersByNickname = [];
foreach ($manifest['players'] as $player) $expectedPlayersByNickname[$player['nickname']] = $player;
$participantIdentityOk = count($participantRows) === (int) $manifest['requestedStudentCount'];
$participantCountsBySession = [];
$participantRowsBySession = [];
$participantsById = [];
foreach ($participantRows as $participant) {
    $participantId = (int) $participant['id'];
    $sessionId = (int) $participant['session_id'];
    $expected = $expectedPlayersByNickname[(string) $participant['nickname']] ?? null;
    $expectedSessionId = $expected === null
        ? null
        : (int) $manifest['sessions'][(int) $expected['sessionSlot'] - 1]['sessionId'];
    $participantIdentityOk = $participantIdentityOk
        && $expected !== null
        && $sessionId === $expectedSessionId
        && $participant['participant_type'] === $expected['participantType']
        && ((int) $participant['is_removed']) === 0
        && (
            $expected['participantType'] === 'GUEST'
                ? $participant['student_id'] === null
                : (int) $participant['student_id'] === (int) $expected['studentId']
        );
    $participantCountsBySession[$sessionId] = ($participantCountsBySession[$sessionId] ?? 0) + 1;
    $participantRowsBySession[$sessionId][] = $participant;
    $participantsById[$participantId] = $participant;
}
$participantCountsOk = true;
foreach ($expectedSessions as $sessionId => $expected) {
    $participantCountsOk = $participantCountsOk
        && ($participantCountsBySession[$sessionId] ?? 0) === (int) $expected['expectedStudentCount'];
}
recordCheck($checks, 'participant count is exact for every Session', $participantCountsOk, $participantCountsBySession);
recordCheck($checks, 'REGISTERED/GUEST identity and Session association are exact', $participantIdentityOk);
$actualRegisteredStudentIds = array_map(
    'intval',
    array_column(
        array_values(array_filter(
            $participantRows,
            static fn(array $participant): bool => $participant['participant_type'] === 'REGISTERED',
        )),
        'student_id',
    ),
);
$expectedRegisteredStudentIds = verifierIds($manifest['registeredStudentIds'] ?? []);
sort($actualRegisteredStudentIds, SORT_NUMERIC);
sort($expectedRegisteredStudentIds, SORT_NUMERIC);
recordCheck(
    $checks,
    'every synthetic REGISTERED Student is used by exactly one Participant',
    $actualRegisteredStudentIds === $expectedRegisteredStudentIds
        && count(array_unique($actualRegisteredStudentIds)) === count($actualRegisteredStudentIds),
);
recordCheck(
    $checks,
    'reconnect did not create duplicate participants',
    count(array_unique(array_column($participantRows, 'nickname'))) === count($participantRows),
);

$manifestParticipantIds = verifierIds($manifest['resources']['participantIds'] ?? []);
$actualParticipantIds = array_map('intval', array_column($participantRows, 'id'));
sort($manifestParticipantIds, SORT_NUMERIC);
sort($actualParticipantIds, SORT_NUMERIC);
recordCheck($checks, 'finalized manifest contains every created Participant ID', $manifestParticipantIds === $actualParticipantIds);

$answerRows = fetchAll($connection, <<<SQL
SELECT
    pa.id,
    pa.session_participant_id,
    pa.session_question_id,
    pa.selected_option_ids,
    pa.is_correct,
    pa.response_time_ms,
    pa.points_awarded,
    sp.session_id AS participant_session_id,
    sq.session_id AS question_session_id
FROM participant_answers pa
INNER JOIN session_participants sp ON sp.id = pa.session_participant_id
INNER JOIN session_questions sq ON sq.id = pa.session_question_id
WHERE sp.session_id IN ($sessionClause)
ORDER BY pa.id
SQL, $sessionParameters);
[$participantSessionClause, $participantSessionParameters] = verifierIn($sessionIds, 'participant_session');
[$questionSessionClause, $questionSessionParameters] = verifierIn($sessionIds, 'question_session');
$foreignCrossSessionRows = fetchAll($connection, <<<SQL
SELECT pa.id
FROM participant_answers pa
INNER JOIN session_participants sp ON sp.id = pa.session_participant_id
INNER JOIN session_questions sq ON sq.id = pa.session_question_id
WHERE (
    sp.session_id IN ($participantSessionClause)
    OR sq.session_id IN ($questionSessionClause)
)
AND sp.session_id <> sq.session_id
SQL, $participantSessionParameters + $questionSessionParameters);
$expectedAnswerCount = (int) $manifest['requestedStudentCount'] * (int) $manifest['questionCount'];
recordCheck(
    $checks,
    'every Player has one accepted answer for every Question',
    count($answerRows) === $expectedAnswerCount,
    ['expected' => $expectedAnswerCount, 'actual' => count($answerRows)],
);
$uniqueAnswerPairs = [];
$duplicateAnswerOk = true;
$crossSessionOk = true;
$selectedOptionsOk = true;
$canonicalPointsOk = true;
$scoreSums = [];
$answerAggregates = [];
$calculator = new AnswerScoreCalculator();
foreach ($answerRows as $answer) {
    $participantId = (int) $answer['session_participant_id'];
    $questionId = (int) $answer['session_question_id'];
    $pair = sprintf('%d:%d', $participantId, $questionId);
    if (isset($uniqueAnswerPairs[$pair])) $duplicateAnswerOk = false;
    $uniqueAnswerPairs[$pair] = true;
    $question = $questions[$questionId] ?? null;
    $crossSessionOk = $crossSessionOk
        && (int) $answer['participant_session_id'] === (int) $answer['question_session_id']
        && $question !== null
        && isset($participantsById[$participantId]);
    if ($question === null) {
        $selectedOptionsOk = false;
        $canonicalPointsOk = false;
        continue;
    }
    $selected = json_decode((string) $answer['selected_option_ids'], true, 512, JSON_THROW_ON_ERROR);
    $selectionCountValid = is_array($selected)
        && array_is_list($selected)
        && count($selected) === count(array_unique($selected))
        && (
            $question['question_type'] === 'MULTIPLE_CHOICE'
                ? count($selected) >= 2 && count($selected) <= 3
                : count($selected) === 1
        );
    $optionMembershipValid = $selectionCountValid
        && count(array_diff($selected, $question['option_ids'])) === 0;
    $selectedOptionsOk = $selectedOptionsOk && $optionMembershipValid;
    $sortedSelected = $selected;
    $sortedCorrect = $question['correct_option_ids'];
    sort($sortedSelected, SORT_NUMERIC);
    sort($sortedCorrect, SORT_NUMERIC);
    $isCorrect = $sortedSelected === $sortedCorrect;
    $canonicalPoints = $calculator->calculate(
        $isCorrect,
        $question['max_points'],
        (int) $answer['response_time_ms'],
        $question['time_limit_seconds'],
    );
    $canonicalPointsOk = $canonicalPointsOk
        && (bool) (int) $answer['is_correct'] === $isCorrect
        && (int) $answer['points_awarded'] === $canonicalPoints;
    $scoreSums[$participantId] = ($scoreSums[$participantId] ?? 0) + (int) $answer['points_awarded'];
    $answerAggregates[$participantId]['answer_count'] = ($answerAggregates[$participantId]['answer_count'] ?? 0) + 1;
    $answerAggregates[$participantId]['correct_answer_count'] = ($answerAggregates[$participantId]['correct_answer_count'] ?? 0) + ($isCorrect ? 1 : 0);
    $answerAggregates[$participantId]['total_response_time_ms'] = ($answerAggregates[$participantId]['total_response_time_ms'] ?? 0) + (int) $answer['response_time_ms'];
}
recordCheck($checks, 'no participant has more than one accepted answer per Question', $duplicateAnswerOk);
recordCheck(
    $checks,
    'no answer crosses a Participant Session and Question Session boundary',
    $crossSessionOk && $foreignCrossSessionRows === [],
    ['independentlyFoundCrossSessionAnswers' => count($foreignCrossSessionRows)],
);
recordCheck($checks, 'every answer uses valid option IDs and type-appropriate cardinality', $selectedOptionsOk);
recordCheck($checks, 'stored correctness and awarded points are canonical', $canonicalPointsOk);

$totalsOk = true;
foreach ($participantRows as $participant) {
    $participantId = (int) $participant['id'];
    $totalsOk = $totalsOk && (int) $participant['total_score'] === ($scoreSums[$participantId] ?? 0);
}
recordCheck($checks, 'stored participant totals equal canonical awarded answer points', $totalsOk);

$repositoryResults = new MySqlQuizSessionResultRepository($database);
$leaderboardOk = true;
$leaderboards = [];
foreach ($expectedSessions as $sessionId => $_) {
    $independentRows = [];
    foreach ($participantRowsBySession[$sessionId] ?? [] as $participant) {
        $participantId = (int) $participant['id'];
        $aggregate = $answerAggregates[$participantId] ?? [];
        $independentRows[] = [
            'id' => $participantId,
            'total_score' => (int) $participant['total_score'],
            'correct_answer_count' => (int) ($aggregate['correct_answer_count'] ?? 0),
            'answer_count' => (int) ($aggregate['answer_count'] ?? 0),
            'total_response_time_ms' => (int) ($aggregate['total_response_time_ms'] ?? 0),
            'joined_at' => (string) $participant['joined_at'],
        ];
    }
    $independentIds = independentlyOrderedParticipantIds($independentRows);
    $repositoryIds = array_map(
        static fn($result): int => $result->participantId,
        $repositoryResults->findFinalParticipantResults($sessionId),
    );
    $leaderboardOk = $leaderboardOk && $independentIds === $repositoryIds;
    $leaderboards[(string) $sessionId] = $independentIds;
}
recordCheck($checks, 'leaderboard ordering is independently derivable from stored totals and tie-breakers', $leaderboardOk);

$manifestAnswerIds = verifierIds($manifest['resources']['answerIds'] ?? []);
$actualAnswerIds = array_map('intval', array_column($answerRows, 'id'));
sort($manifestAnswerIds, SORT_NUMERIC);
sort($actualAnswerIds, SORT_NUMERIC);
recordCheck($checks, 'finalized manifest contains every created answer ID', $manifestAnswerIds === $actualAnswerIds);

$passed = !in_array(false, array_column($checks, 'passed'), true);
$result = [
    'schemaVersion' => 1,
    'runId' => $manifest['runId'],
    'checkedAt' => gmdate(DATE_ATOM),
    'passed' => $passed,
    'expectedParticipantCount' => (int) $manifest['requestedStudentCount'],
    'actualParticipantCount' => count($participantRows),
    'expectedAnswerCount' => $expectedAnswerCount,
    'actualAnswerCount' => count($answerRows),
    'checks' => $checks,
    'derivedLeaderboardParticipantIdsBySession' => $leaderboards,
];
verifierWriteJson($outputPath, $result);

if (!$passed) {
    fwrite(STDERR, "Database correctness verification failed.\n");
    exit(1);
}

echo "Database correctness verification passed.\n";
