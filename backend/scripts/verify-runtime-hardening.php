<?php

declare(strict_types=1);

use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\RequestIdGenerator;
use CodeLandQuiz\Model\ParticipantType;
use CodeLandQuiz\Observability\EventLoopLagTracker;
use CodeLandQuiz\Observability\RuntimeLogger;
use CodeLandQuiz\WebSocket\ParticipantConnectionRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertRuntime(mixed $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$requestIds = new RequestIdGenerator();
$firstRequestId = $requestIds->generate();
$secondRequestId = $requestIds->generate();
assertRuntime(
    $firstRequestId !== $secondRequestId
        && preg_match('/^[a-f0-9]{24}$/D', $firstRequestId) === 1
        && preg_match('/^[a-f0-9]{24}$/D', $secondRequestId) === 1,
    'Request IDs are not unique compact cryptographic values.',
);

$context = new RequestContext($firstRequestId, 'GET', '/verification');
$context->activate();
RequestContext::recordCurrentResponseStatus(503);
assertRuntime(
    $context->getRequestId() === $firstRequestId
        && $context->getResponseStatus() === 503,
    'Request context did not retain request correlation/status state.',
);
$context->deactivate();

$logPath = tempnam(sys_get_temp_dir(), 'clq-runtime-log-');
assertRuntime(is_string($logPath), 'Temporary log path could not be created.');
$previousErrorLog = ini_get('error_log');
ini_set('error_log', $logPath);
$logger = new RuntimeLogger(debugEnabled: false);
$logger->error('runtime.verification', [
    'requestId' => str_repeat('a', 1_024),
    'exception' => RuntimeException::class,
    'password' => 'must-not-appear',
]);
$logContents = file_get_contents($logPath);
ini_set('error_log', is_string($previousErrorLog) ? $previousErrorLog : '');
unlink($logPath);
assertRuntime(
    is_string($logContents)
        && str_contains($logContents, 'runtime.verification')
        && !str_contains($logContents, 'must-not-appear')
        && !str_contains($logContents, 'password')
        && substr_count($logContents, 'a') < 300,
    'Structured runtime logs did not redact or bound unapproved input.',
);

$monotonicNanoseconds = 0;
$lagTracker = new EventLoopLagTracker(
    1_000,
    static function () use (&$monotonicNanoseconds): int {
        return $monotonicNanoseconds;
    },
);
$lagTracker->start();
$monotonicNanoseconds = 1_125_000_000;
assertRuntime(
    abs($lagTracker->sample() - 125.0) < 0.001,
    'Controlled event-loop delay was not measured.',
);
$monotonicNanoseconds = 2_125_000_000;
assertRuntime(
    abs($lagTracker->sample()) < 0.001,
    'Event-loop lag did not recover after the controlled delay.',
);

$registryNow = 10_000_000_000;
$registry = new ParticipantConnectionRegistry(
    static function () use (&$registryNow): int {
        return $registryNow;
    },
);
$expiresAt = new DateTimeImmutable('2099-01-01T00:00:00+00:00');
$connectionAId = $registry->registerPending(20);
$registry->authenticate(
    fileDescriptor: 20,
    connectionId: $connectionAId,
    participantId: 7,
    sessionId: 9,
    participantType: ParticipantType::GUEST,
    studentId: null,
    participantTokenExpiresAt: $expiresAt,
);
$registryNow += 25_000_000_000;
assertRuntime(
    $registry->touchAuthenticated(20, $connectionAId)
        && $registry->findAuthenticated(20)?->idleNanoseconds($registryNow)
            === 0,
    'Valid inbound participant activity did not refresh monotonic last-seen state.',
);

$connectionBId = $registry->registerPending(21);
$replacedFileDescriptor = $registry->authenticate(
    fileDescriptor: 21,
    connectionId: $connectionBId,
    participantId: 7,
    sessionId: 9,
    participantType: ParticipantType::GUEST,
    studentId: null,
    participantTokenExpiresAt: $expiresAt,
);
assertRuntime(
    $replacedFileDescriptor === 20
        && $registry->removeIfCurrent(20, $connectionAId) === null
        && $registry->findCurrentFileDescriptorByParticipantId(7) === 21,
    'Late cleanup for a replaced socket displaced the authoritative reconnect.',
);

$reusedConnectionId = $registry->registerPending(20);
$registry->authenticate(
    fileDescriptor: 20,
    connectionId: $reusedConnectionId,
    participantId: 8,
    sessionId: 9,
    participantType: ParticipantType::GUEST,
    studentId: null,
    participantTokenExpiresAt: $expiresAt,
);
assertRuntime(
    $registry->removeIfCurrent(20, $connectionAId) === null
        && $registry->isCurrent(20, $reusedConnectionId),
    'A delayed operation for an old connection ID affected a reused fd.',
);

$repositoryRoot = dirname(__DIR__, 2);
$sourceRoot = dirname(__DIR__) . '/src';
$application = file_get_contents($sourceRoot . '/Application.php');
$gateway = file_get_contents(
    $sourceRoot . '/WebSocket/ParticipantWebSocketGateway.php',
);
$connectionService = file_get_contents(
    $sourceRoot . '/Game/ParticipantConnectionService.php',
);
$supervisor = file_get_contents(
    $sourceRoot . '/Runtime/OpenSwooleRuntimeSupervisor.php',
);
$participantRepository = file_get_contents(
    $sourceRoot . '/Repository/MySqlSessionParticipantRepository.php',
);
$router = file_get_contents($sourceRoot . '/Support/Router.php');
$hasRepositoryRoot = is_file(
    $repositoryRoot . '/compose.production.yaml',
);
$nginx = $hasRepositoryRoot
    ? file_get_contents($repositoryRoot . '/docker/nginx/default.conf.template')
    : null;
$compose = $hasRepositoryRoot
    ? file_get_contents($repositoryRoot . '/compose.production.yaml')
    : null;
$frontend = $hasRepositoryRoot
    ? file_get_contents(
        $repositoryRoot
            . '/frontend/src/app/features/public/player/data-access/player-game.store.ts',
    )
    : null;

assertRuntime(
    is_string($application)
        && str_contains($application, "'worker_num' => 1")
        && str_contains($application, "'max_request' => 0")
        && str_contains($application, "'max_conn'")
        && str_contains($application, "'max_coroutine'")
        && str_contains($application, "'heartbeat_check_interval'")
        && str_contains($application, "'heartbeat_idle_time'")
        && !str_contains($application, "'reactor_num'"),
    'The explicit single-worker OpenSwoole runtime policy is incomplete.',
);
assertRuntime(
    is_string($gateway)
        && str_contains($gateway, "HEARTBEAT_ACK")
        && str_contains($gateway, 'touchAuthenticated')
        && strpos($gateway, 'HEARTBEAT_ACK_MESSAGE_TYPE')
            < strpos($gateway, 'recordAnswerAttempt')
        && str_contains($gateway, 'removeIfCurrent')
        && str_contains($gateway, 'shouldMarkDisconnected'),
    'Heartbeat fast-path or stale/reconnect race protection is incomplete.',
);
assertRuntime(
    is_string($connectionService)
        && strpos($connectionService, '$shouldMarkDisconnected()')
            < strpos($connectionService, 'markDisconnected($participant->id)'),
    'Presence is not rechecked after acquiring the participant row lock.',
);
assertRuntime(
    is_string($supervisor)
        && substr_count($supervisor, 'Timer::tick(') === 1
        && str_contains($supervisor, 'Timer::clear(')
        && str_contains($supervisor, 'tickRunning'),
    'Runtime recurring timer lifecycle is not singular and bounded.',
);
assertRuntime(
    is_string($application)
        && str_contains($application, "'workerExit'")
        && strpos($application, "'workerExit'")
            < strpos($application, "'workerStop'")
        && str_contains($application, 'workerExitCleanupScheduled')
        && str_contains($application, 'presence.worker_exit_reconciled')
        && !str_contains($application, 'presence.shutdown_reconciled'),
    'Graceful lifecycle work is not ordered before the event loop stops.',
);
assertRuntime(
    is_string($participantRepository)
        && str_contains($participantRepository, "qs.status IN ('WAITING', 'ACTIVE')")
        && str_contains($participantRepository, 'sp.is_connected = FALSE')
        && !str_contains(
            substr(
                $participantRepository,
                (int) strpos($participantRepository, 'RECONCILE_LIVE_SESSION_PRESENCE_SQL'),
                600,
            ),
            'DELETE ',
        ),
    'Startup presence reconciliation is missing or mutates domain membership.',
);
assertRuntime(
    is_string($router)
        && str_contains($router, "header('X-Request-ID'")
        && str_contains($router, 'http.request.completed')
        && str_contains($router, "'coroutineId'"),
    'HTTP request correlation or structured diagnostics are incomplete.',
);

if (!$hasRepositoryRoot) {
    echo "OpenSwoole runtime hardening verification passed.\n";

    exit(0);
}

assertRuntime(
    is_string($nginx)
        && substr_count($nginx, 'proxy_read_timeout 150s;') === 2
        && str_contains($nginx, 'location = /internal/metrics')
        && str_contains($nginx, 'return 404;')
        && str_contains($nginx, 'location = /ready'),
    'Nginx heartbeat timeout, readiness or metrics privacy policy is incomplete.',
);
assertRuntime(
    is_string($compose)
        && str_contains($compose, 'stop_grace_period: 20s')
        && str_contains($compose, 'soft: 8192')
        && str_contains($compose, 'hard: 8192')
        && str_contains($compose, "9501/ready"),
    'Production graceful stop, nofile or readiness healthcheck is incomplete.',
);
assertRuntime(
    is_string($frontend)
        && str_contains($frontend, "case 'HEARTBEAT':")
        && str_contains($frontend, "type: 'HEARTBEAT_ACK'")
        && !str_contains($frontend, 'heartbeatTimer'),
    'Player heartbeat response is missing or introduced a client interval.',
);

echo "OpenSwoole runtime hardening verification passed.\n";
