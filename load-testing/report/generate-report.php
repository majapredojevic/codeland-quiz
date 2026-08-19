<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>|null
 */
function reportJson(string $path): ?array
{
    if (!is_file($path)) return null;
    $contents = file_get_contents($path);
    if (!is_string($contents)) return null;
    try {
        $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        return is_array($value) && !array_is_list($value) ? $value : null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * @return array<int, array<string, string>>
 */
function reportCsv(string $path): array
{
    if (!is_file($path)) return [];
    $stream = fopen($path, 'rb');
    if ($stream === false) return [];
    $header = fgetcsv($stream, null, ',', '"', '');
    if (!is_array($header)) {
        fclose($stream);
        return [];
    }
    $rows = [];
    while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
        if (count($values) !== count($header)) continue;
        $row = array_combine($header, $values);
        if (is_array($row)) $rows[] = $row;
    }
    fclose($stream);
    return $rows;
}

function reportNumber(mixed $value): ?float
{
    return is_numeric($value) ? (float) $value : null;
}

function maximumColumn(array $rows, string $column): ?float
{
    $values = array_values(array_filter(
        array_map(static fn(array $row): ?float => reportNumber($row[$column] ?? null), $rows),
        static fn(?float $value): bool => $value !== null,
    ));
    return $values === [] ? null : max($values);
}

function firstColumn(array $rows, string $column): ?float
{
    foreach ($rows as $row) {
        $value = reportNumber($row[$column] ?? null);
        if ($value !== null) return $value;
    }
    return null;
}

function lastColumn(array $rows, string $column): ?float
{
    for ($index = count($rows) - 1; $index >= 0; $index--) {
        $value = reportNumber($rows[$index][$column] ?? null);
        if ($value !== null) return $value;
    }
    return null;
}

function deltaColumn(array $rows, string $column): ?float
{
    $start = firstColumn($rows, $column);
    $end = lastColumn($rows, $column);
    return $start === null || $end === null ? null : $end - $start;
}

function shown(mixed $value, int $decimals = 2): string
{
    if ($value === null || $value === '') return 'NOT AVAILABLE';
    if (is_bool($value)) return $value ? 'YES' : 'NO';
    if (is_numeric($value)) return number_format((float) $value, $decimals, '.', '');
    return (string) $value;
}

function bytesShown(mixed $value): string
{
    if (!is_numeric($value)) return 'NOT AVAILABLE';
    $bytes = (float) $value;
    foreach (['B', 'KiB', 'MiB', 'GiB', 'TiB'] as $unit) {
        if ($bytes < 1024 || $unit === 'TiB') return sprintf('%.2f %s', $bytes, $unit);
        $bytes /= 1024;
    }
    return 'NOT AVAILABLE';
}

function percentile(array $values, float $percentile): ?float
{
    if ($values === []) return null;
    sort($values, SORT_NUMERIC);
    $rank = max(1, (int) ceil($percentile * count($values)));
    return (float) $values[min(count($values) - 1, $rank - 1)];
}

function epochMilliseconds(string $timestamp): ?float
{
    try {
        $date = new DateTimeImmutable($timestamp);
        return (float) $date->format('U.u') * 1000;
    } catch (Throwable) {
        return null;
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function broadcastRows(string $rawPath): array
{
    if (!is_file($rawPath)) return [];
    $stream = fopen($rawPath, 'rb');
    if ($stream === false) return [];
    $actions = [];
    $receipts = [];

    while (($line = fgets($stream)) !== false) {
        try {
            $point = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            continue;
        }
        if (($point['type'] ?? null) !== 'Point') continue;
        $metric = $point['metric'] ?? null;
        if (!in_array($metric, ['broadcast_action_marker', 'broadcast_receipt_marker'], true)) continue;
        $tags = $point['data']['tags'] ?? [];
        if (!is_array($tags)) continue;
        $key = implode('|', [
            $tags['sessionSlot'] ?? '',
            $tags['questionIndex'] ?? '',
            $tags['eventType'] ?? '',
        ]);
        $time = epochMilliseconds((string) ($point['data']['time'] ?? ''));
        if ($time === null) continue;
        if ($metric === 'broadcast_action_marker') {
            $actions[$key] = isset($actions[$key]) ? min($actions[$key], $time) : $time;
        } else {
            $sampleCount = max(1, (int) round((float) ($point['data']['value'] ?? 1)));
            for ($sample = 0; $sample < $sampleCount; $sample++) {
                $receipts[$key][] = $time;
            }
        }
    }
    fclose($stream);
    $rows = [];

    foreach ($actions as $key => $actionTime) {
        [$slot, $question, $event] = explode('|', $key, 3);
        $latencies = [];
        foreach ($receipts[$key] ?? [] as $receiptTime) {
            $latencies[] = max(0.0, $receiptTime - $actionTime);
        }
        $rows[] = [
            'sessionSlot' => (int) $slot,
            'questionIndex' => (int) $question,
            'eventType' => $event,
            'recipientCount' => count($latencies),
            'firstMs' => $latencies === [] ? null : min($latencies),
            'p50Ms' => percentile($latencies, 0.50),
            'p95Ms' => percentile($latencies, 0.95),
            'p99Ms' => percentile($latencies, 0.99),
            'lastMs' => $latencies === [] ? null : max($latencies),
        ];
    }
    usort($rows, static fn(array $left, array $right): int => [
        $left['sessionSlot'], $left['questionIndex'], $left['eventType'],
    ] <=> [
        $right['sessionSlot'], $right['questionIndex'], $right['eventType'],
    ]);
    return $rows;
}

/**
 * @return array<string, mixed>|null
 */
function metricValues(?array $summary, string $name): ?array
{
    $values = $summary['metrics'][$name]['values'] ?? null;
    return is_array($values) ? $values : null;
}

function metricLine(?array $summary, string $name): string
{
    $values = metricValues($summary, $name);
    if ($values === null) return sprintf('| `%s` | NOT AVAILABLE |', $name);
    $count = $values['count'] ?? $values['passes'] ?? null;
    $p50 = $values['med'] ?? null;
    $p95 = $values['p(95)'] ?? null;
    $p99 = $values['p(99)'] ?? null;
    $max = $values['max'] ?? null;
    return sprintf(
        '| `%s` | %s | %s | %s | %s | %s |',
        $name,
        shown($count, 0),
        shown($p50),
        shown($p95),
        shown($p99),
        shown($max),
    );
}

$runDirectory = $argv[1] ?? '';
if ($runDirectory === '' || !is_dir($runDirectory)) {
    fwrite(STDERR, "Usage: php generate-report.php <run-directory>\n");
    exit(1);
}
$runDirectory = rtrim($runDirectory, '/');
$manifest = reportJson($runDirectory . '/manifest.json');
if ($manifest === null) throw new RuntimeException('Manifest is required to generate the report.');
$environment = reportJson($runDirectory . '/environment.json');
$summary = reportJson($runDirectory . '/k6-summary.json');
$correctness = reportJson($runDirectory . '/correctness.json');
$cleanup = reportJson($runDirectory . '/cleanup.json');
$status = reportJson($runDirectory . '/run-status.json');
$runtime = reportCsv($runDirectory . '/runtime-metrics.csv');
$mysql = reportCsv($runDirectory . '/mysql-metrics.csv');
$docker = reportCsv($runDirectory . '/docker-stats.csv');
$broadcast = broadcastRows($runDirectory . '/k6-raw.json');
$expectedBroadcastRows = (int) $manifest['sessionCount']
    * (((int) $manifest['questionCount'] * 2) + 1);
$expectedRecipientsBySlot = [];
foreach ($manifest['sessions'] ?? [] as $session) {
    $expectedRecipientsBySlot[(int) $session['sessionSlot']] = (int) $session['expectedStudentCount'];
}
$broadcastComplete = count($broadcast) === $expectedBroadcastRows;
foreach ($broadcast as $row) {
    $broadcastComplete = $broadcastComplete
        && isset($expectedRecipientsBySlot[$row['sessionSlot']])
        && $row['recipientCount'] === $expectedRecipientsBySlot[$row['sessionSlot']];
}
$correctnessPassed = ($correctness['passed'] ?? false) === true;
$cleanupPassed = ($cleanup['passed'] ?? false) === true;
$k6Passed = (int) ($status['k6ExitCode'] ?? -1) === 0;
$orchestrationPassed = ($status['failures'] ?? null) === [];
$requiredMetricsPresent = $runtime !== [] && $mysql !== [] && $docker !== [] && $summary !== null;
$valid = $correctnessPassed
    && $cleanupPassed
    && $k6Passed
    && $orchestrationPassed
    && $requiredMetricsPresent
    && $broadcastComplete;
$lines = [];
$lines[] = '# CodeLand Quiz load-test report';
$lines[] = '';
$lines[] = sprintf('- Run ID: `%s`', $manifest['runId']);
$lines[] = sprintf('- Result: **%s**', $valid ? 'VALID' : 'INVALID');
$lines[] = '- Scope: harness validation/baseline measurement only; this report is not a capacity claim.';
$lines[] = '';
$lines[] = '## TEST CONFIGURATION';
$lines[] = '';
$lines[] = sprintf('- Mode: `%s`', $manifest['mode']);
$lines[] = sprintf('- Students: %d', $manifest['requestedStudentCount']);
$lines[] = sprintf('- Sessions/Teachers: %d', $manifest['sessionCount']);
$lines[] = sprintf('- Distribution: `%s`', implode(', ', $manifest['studentDistribution']));
$lines[] = sprintf('- Registered/Guest: %d / %d', $manifest['configuration']['registeredParticipantCount'], $manifest['configuration']['guestParticipantCount']);
$lines[] = sprintf('- Questions per Session: %d (TRUE_FALSE, SINGLE_CHOICE and MULTIPLE_CHOICE; one image Question)', $manifest['questionCount']);
$lines[] = sprintf('- Seed: `%s`', $manifest['seed']);
$lines[] = sprintf('- Scheduled synchronized gameplay start: `%s`', $manifest['scheduledStartAt'] ?? 'NOT AVAILABLE');
$lines[] = sprintf('- Executor: `%s`, one iteration per VU', $manifest['configuration']['executor']);
$lines[] = sprintf('- Environment: %s', ($status['warmed'] ?? false) ? 'warmed (separate unrecorded warm-up)' : 'cold/no explicit warm-up');
$lines[] = sprintf('- k6: `%s` using `k6/websockets`', $environment['versions']['k6'] ?? 'NOT AVAILABLE');
$lines[] = sprintf('- Git commit: `%s`', $environment['gitCommit'] ?? 'NOT AVAILABLE');
$lines[] = sprintf('- Git worktree dirty when recorded: %s', shown($environment['gitDirty'] ?? null));
$lines[] = sprintf('- Docker CPUs / memory: %s / %s', shown($environment['docker']['cpuCount'] ?? null, 0), bytesShown($environment['docker']['memoryBytes'] ?? null));
$lines[] = sprintf('- OpenSwoole worker_num / max_conn / max_coroutine: %s / %s / %s', shown($environment['runtime']['workerNum'] ?? null, 0), shown($environment['runtime']['maxConn'] ?? null, 0), shown($environment['runtime']['maxCoroutine'] ?? null, 0));
$lines[] = sprintf('- MySQL max_connections: %s', shown($environment['mysql']['maxConnections'] ?? null, 0));
$lines[] = '';
$lines[] = '## CORRECTNESS';
$lines[] = '';
$lines[] = sprintf('**Database correctness: %s.** Performance data is invalid whenever this section fails.', $correctnessPassed ? 'PASS' : 'FAIL');
$lines[] = '';
foreach ($correctness['checks'] ?? [] as $check) {
    $lines[] = sprintf('- %s %s', ($check['passed'] ?? false) ? 'PASS:' : 'FAIL:', $check['name'] ?? 'unnamed check');
}
$lines[] = '';
$lines[] = '## CLIENT-SIDE / K6';
$lines[] = '';
$lines[] = '| Metric | samples | p50 (ms) | p95 (ms) | p99 (ms) | max (ms) |';
$lines[] = '| --- | ---: | ---: | ---: | ---: | ---: |';
foreach ([
    'http_req_duration',
    'ws_connecting',
    'ws_session_duration',
    'app_join_latency',
    'app_ws_authentication_latency',
    'app_answer_acknowledgement_latency',
    'app_answer_result_latency',
    'app_reconnect_latency',
    'app_media_request_latency',
] as $metric) {
    $lines[] = metricLine($summary, $metric);
}
$lines[] = '';
$lines[] = 'Answer acknowledgement latency is measured from sending the production `ANSWER_SUBMIT` frame until the same socket receives authoritative `ANSWER_ACCEPTED` for that Question. It is not socket `send()` return time.';
$lines[] = 'Answer result latency spans `ANSWER_SUBMIT` to the per-Player `ANSWER_RESULT` reveal, so it intentionally includes the remaining Question-open interval and Teacher close action.';
$answerSamples = metricValues($summary, 'app_answer_acknowledgement_latency')['count'] ?? 0;
if ((int) $answerSamples < 100) {
    $lines[] = sprintf('The answer-latency p99 has only %d samples and should be treated as descriptive, not stable.', $answerSamples);
}
$lines[] = '';
$lines[] = 'Built-in HTTP/WebSocket counts:';
$lines[] = '';
foreach (['http_reqs', 'http_req_failed', 'ws_sessions', 'ws_msgs_sent', 'ws_msgs_received'] as $metric) {
    $values = metricValues($summary, $metric);
    $lines[] = sprintf('- `%s`: `%s`', $metric, $values === null ? 'NOT AVAILABLE' : json_encode($values, JSON_UNESCAPED_SLASHES));
}
$lines[] = '';
$lines[] = 'Application correctness/success metrics:';
$lines[] = '';
foreach (['app_join_success', 'app_ws_authentication_success', 'app_answer_acknowledgement_success', 'app_reconnect_success', 'app_final_result_success', 'app_player_flow_success', 'app_teacher_flow_success', 'app_messages_sent', 'app_messages_received', 'app_heartbeat_acknowledgements'] as $metric) {
    $values = metricValues($summary, $metric);
    $lines[] = sprintf('- `%s`: `%s`', $metric, $values === null ? 'NOT AVAILABLE' : json_encode($values, JSON_UNESCAPED_SLASHES));
}
$lines[] = '';
$lines[] = '## OPENSWOOLE';
$lines[] = '';
$lines[] = sprintf('- Maximum active server connections: %s', shown(maximumColumn($runtime, 'server.connections_active'), 0));
$lines[] = sprintf('- Maximum authenticated Player WebSockets: %s', shown(maximumColumn($runtime, 'webSocket.authenticatedConnections'), 0));
$lines[] = sprintf('- Maximum pending Player WebSockets: %s', shown(maximumColumn($runtime, 'webSocket.pendingConnections'), 0));
$lines[] = sprintf('- Maximum current coroutines: %s', shown(maximumColumn($runtime, 'coroutines.active'), 0));
$lines[] = sprintf('- Peak coroutine metric: %s', shown(maximumColumn($runtime, 'coroutines.peak'), 0));
$lines[] = sprintf('- Maximum application event-loop lag: %s ms', shown(maximumColumn($runtime, 'eventLoop.maximumLagMs')));
$lines[] = sprintf('- Memory start / end / peak: %s / %s / %s', bytesShown(firstColumn($runtime, 'memory.usageBytes')), bytesShown(lastColumn($runtime, 'memory.usageBytes')), bytesShown(maximumColumn($runtime, 'memory.peakUsageBytes')));
$lines[] = sprintf('- Heartbeat stale cleanups delta: %s', shown(deltaColumn($runtime, 'webSocket.staleConnectionsClosed'), 0));
$lines[] = sprintf('- HTTP requests observed delta: %s', shown(deltaColumn($runtime, 'http.requestsObserved'), 0));
$lines[] = '';
$lines[] = '## MYSQL';
$lines[] = '';
$lines[] = sprintf('- Maximum sampled `Threads_connected`: %s', shown(maximumColumn($mysql, 'Threads_connected'), 0));
$lines[] = sprintf('- Maximum sampled `Threads_running`: %s', shown(maximumColumn($mysql, 'Threads_running'), 0));
$lines[] = sprintf('- Maximum sampled `Innodb_row_lock_current_waits`: %s', shown(maximumColumn($mysql, 'Innodb_row_lock_current_waits'), 0));
$lines[] = sprintf('- Configured `max_connections`: %s', shown(firstColumn($mysql, 'max_connections'), 0));
foreach (['Connections', 'Aborted_connects', 'Connection_errors_internal', 'Connection_errors_max_connections', 'Innodb_row_lock_waits', 'Innodb_row_lock_time', 'Innodb_deadlocks'] as $counter) {
    $lines[] = sprintf('- `%s` start/end delta: %s', $counter, shown(deltaColumn($mysql, $counter), 0));
}
$lines[] = '- Server-lifetime counters are reported as run-window start/end deltas; unavailable counters remain explicitly NOT AVAILABLE.';
$lines[] = '';
$lines[] = '## DOCKER RESOURCES';
$lines[] = '';
$lines[] = '| Service | max CPU | max memory | max memory % |';
$lines[] = '| --- | ---: | ---: | ---: |';
foreach (['backend', 'mysql', 'nginx', 'k6'] as $service) {
    $serviceRows = array_values(array_filter($docker, static fn(array $row): bool => ($row['service'] ?? '') === $service));
    $lines[] = sprintf(
        '| %s | %s%% | %s | %s%% |',
        $service,
        shown(maximumColumn($serviceRows, 'cpuPercent')),
        bytesShown(maximumColumn($serviceRows, 'memoryUsageBytes')),
        shown(maximumColumn($serviceRows, 'memoryPercent')),
    );
}
$lines[] = '';
$lines[] = 'The k6 row is part of validity assessment: backend measurements do not establish capacity if the generator itself is saturated.';
$lines[] = '';
$lines[] = '## BROADCAST';
$lines[] = '';
$lines[] = 'Raw timestamp markers correlate the Teacher HTTP action marker to receipt of the unmodified production event by Players. No test-only protocol field is used.';
$lines[] = sprintf('Unique Player/event receipt coverage: **%s**.', $broadcastComplete ? 'PASS' : 'FAIL');
$lines[] = '';
$lines[] = '| Slot | Question | Production event | recipients | first (ms) | p50 | p95 | p99 | last |';
$lines[] = '| ---: | ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: |';
foreach ($broadcast as $row) {
    $lines[] = sprintf(
        '| %d | %d | `%s` | %d | %s | %s | %s | %s | %s |',
        $row['sessionSlot'],
        $row['questionIndex'],
        $row['eventType'],
        $row['recipientCount'],
        shown($row['firstMs']),
        shown($row['p50Ms']),
        shown($row['p95Ms']),
        shown($row['p99Ms']),
        shown($row['lastMs']),
    );
}
if ((int) $manifest['requestedStudentCount'] < 100) {
    $lines[] = '';
    $lines[] = 'Per-event p99 values have few recipients in this smoke run and are included only for pipeline verification.';
}
$lines[] = '';
$lines[] = '## RECONNECT';
$lines[] = '';
$reconnectMetric = metricValues($summary, 'app_reconnect_latency');
$lines[] = sprintf('- Deterministically selected Players: %d', $manifest['configuration']['reconnectPlayerCount']);
$lines[] = sprintf('- Successful reconnect samples: %s', shown($reconnectMetric['count'] ?? null, 0));
$lines[] = sprintf('- Reconnect p50 / p95 / max: %s / %s / %s ms', shown($reconnectMetric['med'] ?? null), shown($reconnectMetric['p(95)'] ?? null), shown($reconnectMetric['max'] ?? null));
$lines[] = '- Reconnect reuses the issued participant token and never calls join again; the correctness verifier checks that no duplicate Participant row appears.';
$lines[] = '';
$lines[] = '## OBSERVATIONS';
$lines[] = '';
$lines[] = sprintf('- k6 process exit: %s', $k6Passed ? 'PASS' : 'FAIL');
$lines[] = sprintf('- Required metric artifacts present: %s', $requiredMetricsPresent ? 'PASS' : 'FAIL');
$lines[] = sprintf('- Orchestration completed without recorded failures: %s', $orchestrationPassed ? 'PASS' : 'FAIL');
$lines[] = sprintf('- Exact cleanup verification: %s', $cleanupPassed ? 'PASS' : 'FAIL');
$lines[] = '- Safety abort rule: a sustained legitimate-request failure rate at or above 20% after 10 seconds aborts the run; the strict final correctness threshold remains zero legitimate failures.';
$lines[] = '- Latencies are descriptive baseline data; no product latency SLO is asserted.';
$lines[] = '- Results apply only to the recorded Docker/host environment and workload configuration.';
$lines[] = ($environment['gitDirty'] ?? null) === true
    ? '- This implementation smoke was recorded from a dirty worktree; Phase 4B evidence runs should use the reviewed, committed harness state.'
    : '- The recorded Git worktree was clean.';
$lines[] = '- No PDO pool, worker-count change, TaskWorker offload, caching, or other performance optimization was introduced.';
$lines[] = '';
$lines[] = '## HARNESS LIMITATIONS';
$lines[] = '';
$lines[] = '- k6 models protocol-accurate clients, not browser rendering or JavaScript UI cost.';
$lines[] = '- Local self-signed TLS uses k6 certificate-verification bypass only for the allowlisted local hostname; HTTPS/WSS and server-side Origin validation remain active.';
$lines[] = '- Docker Desktop scheduling and host background activity can affect measurements.';
$lines[] = '- A 10-Player smoke validates the harness path only and supplies no evidence for higher-scale capacity.';
$lines[] = '';
$lines[] = sprintf('**READY TO EXECUTE THE 10→500 BASELINE MATRIX: %s**', $valid ? 'YES' : 'NO');
$lines[] = '';

$reportPath = $runDirectory . '/report.md';
$contents = implode(PHP_EOL, $lines);
if (file_put_contents($reportPath, $contents, LOCK_EX) !== strlen($contents)) {
    throw new RuntimeException('report.md could not be written.');
}
echo sprintf("Generated %s\n", $reportPath);
if (!$valid) {
    fwrite(STDERR, "Generated report is INVALID.\n");
    exit(1);
}
