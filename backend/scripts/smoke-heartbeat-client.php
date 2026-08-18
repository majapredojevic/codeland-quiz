<?php

declare(strict_types=1);

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Runtime;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;

require dirname(__DIR__) . '/vendor/autoload.php';

Runtime::enableCoroutine(true, Runtime::HOOK_ALL);

/**
 * This opt-in integration helper deliberately receives the short-lived smoke
 * participant token through an environment variable and never prints it.
 */
$participantToken = getenv('HEARTBEAT_SMOKE_PARTICIPANT_TOKEN');
$participantId = filter_var(
    getenv('HEARTBEAT_SMOKE_PARTICIPANT_ID'),
    FILTER_VALIDATE_INT,
);
$mode = getenv('HEARTBEAT_SMOKE_MODE') ?: 'ack';
$origin = getenv('HEARTBEAT_SMOKE_ORIGIN') ?: 'http://localhost:4200';
$host = getenv('HEARTBEAT_SMOKE_HOST') ?: '127.0.0.1';
$port = (int) (getenv('HEARTBEAT_SMOKE_PORT') ?: 9501);
$runSeconds = (float) (getenv('HEARTBEAT_SMOKE_RUN_SECONDS') ?: 8);
$useTls = filter_var(
    getenv('HEARTBEAT_SMOKE_TLS') ?: 'false',
    FILTER_VALIDATE_BOOL,
);
$allowSelfSignedTls = filter_var(
    getenv('HEARTBEAT_SMOKE_TLS_INSECURE') ?: 'false',
    FILTER_VALIDATE_BOOL,
);
$httpHost = getenv('HEARTBEAT_SMOKE_HTTP_HOST') ?: sprintf(
    '%s:%d',
    $host,
    $port,
);
$answerOptionIdValue = getenv('HEARTBEAT_SMOKE_ANSWER_OPTION_ID');
$answerOptionId = $answerOptionIdValue === false || $answerOptionIdValue === ''
    ? null
    : filter_var($answerOptionIdValue, FILTER_VALIDATE_INT);

if (
    !is_string($participantToken)
    || trim($participantToken) === ''
    || !is_int($participantId)
    || $participantId < 1
    || !in_array($mode, ['ack', 'silent'], true)
    || $port < 1
    || $runSeconds <= 0
    || !is_string($httpHost)
    || trim($httpHost) === ''
    || ($answerOptionId !== null && (!is_int($answerOptionId) || $answerOptionId < 1))
) {
    throw new RuntimeException('Heartbeat smoke environment is invalid.');
}

Coroutine::run(function () use (
    $participantToken,
    $participantId,
    $mode,
    $origin,
    $host,
    $port,
    $runSeconds,
    $useTls,
    $allowSelfSignedTls,
    $httpHost,
    $answerOptionId,
): void {
    $client = new Client($host, $port, $useTls);
    $clientSettings = ['timeout' => max(2.0, $runSeconds + 3.0)];

    if ($useTls && $allowSelfSignedTls) {
        $clientSettings['ssl_verify_peer'] = false;
        $clientSettings['ssl_allow_self_signed'] = true;
    }

    $client->set($clientSettings);
    $client->setHeaders([
        'Host' => $httpHost,
        'Origin' => $origin,
    ]);

    if (!$client->upgrade('/ws/game')) {
        throw new RuntimeException('Heartbeat smoke WebSocket upgrade failed.');
    }

    $startedAt = microtime(true);
    $authenticated = false;
    $heartbeatCount = 0;
    $serverClosed = false;
    $answerSubmitted = false;
    $answerAccepted = false;

    while (microtime(true) - $startedAt < $runSeconds) {
        $frame = $client->recv(1.0);

        if ($frame === false || $frame === '') {
            if (!$client->connected && $authenticated) {
                $serverClosed = true;
                break;
            }

            continue;
        }

        if (!$frame instanceof Frame) {
            continue;
        }

        if ($frame->opcode === Server::WEBSOCKET_OPCODE_CLOSE) {
            $serverClosed = true;
            break;
        }

        $message = json_decode($frame->data, true);

        if (!is_array($message)) {
            continue;
        }

        if (($message['type'] ?? null) === 'AUTHENTICATION_REQUIRED') {
            $client->push(json_encode([
                'type' => 'PARTICIPANT_AUTHENTICATE',
                'payload' => ['participantToken' => $participantToken],
            ], JSON_THROW_ON_ERROR));

            continue;
        }

        if (($message['type'] ?? null) === 'PARTICIPANT_AUTHENTICATED') {
            $authenticated = true;

            continue;
        }

        if (
            ($message['type'] ?? null) === 'QUESTION_STARTED'
            && $answerOptionId !== null
            && !$answerSubmitted
        ) {
            $client->push(json_encode([
                'type' => 'ANSWER_SUBMIT',
                'payload' => ['selectedOptionIds' => [$answerOptionId]],
            ], JSON_THROW_ON_ERROR));
            $answerSubmitted = true;

            continue;
        }

        if (($message['type'] ?? null) === 'ANSWER_ACCEPTED') {
            $answerAccepted = true;

            continue;
        }

        if (($message['type'] ?? null) !== 'HEARTBEAT') {
            continue;
        }

        $heartbeatCount++;

        if ($mode === 'ack') {
            $client->push(json_encode([
                'type' => 'HEARTBEAT_ACK',
                'payload' => new stdClass(),
            ], JSON_THROW_ON_ERROR));
        }
    }

    if (!$authenticated) {
        throw new RuntimeException('Heartbeat smoke client was not authenticated.');
    }

    $database = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST'),
            (int) getenv('DB_PORT'),
            getenv('DB_DATABASE'),
        ),
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $presence = static function () use ($database, $participantId): bool {
        $statement = $database->prepare(
            'SELECT is_connected FROM session_participants WHERE id = :id',
        );
        $statement->execute(['id' => $participantId]);

        return (bool) (int) $statement->fetchColumn();
    };

    if ($mode === 'ack') {
        if (
            $serverClosed
            || $heartbeatCount < 2
            || !$presence()
            || ($answerOptionId !== null && !$answerAccepted)
        ) {
            throw new RuntimeException(
                'Heartbeat ACK client did not remain authoritatively connected.',
            );
        }

        $client->close();
        echo "Healthy heartbeat client verification passed.\n";

        return;
    }

    for ($attempt = 0; $attempt < 30 && $presence(); $attempt++) {
        usleep(100_000);
    }

    if (!$serverClosed || $presence()) {
        throw new RuntimeException(
            'Silent heartbeat client was not closed and reconciled.',
        );
    }

    echo "Silent heartbeat client verification passed.\n";
});
