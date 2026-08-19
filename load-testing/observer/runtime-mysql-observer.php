<?php

declare(strict_types=1);

use CodeLandQuiz\Support\Database;
use CodeLandQuiz\Support\Environment;

require '/var/www/backend/vendor/autoload.php';

const MYSQL_STATUS_NAMES = [
    'Threads_connected',
    'Threads_running',
    'Connections',
    'Aborted_connects',
    'Connection_errors_internal',
    'Connection_errors_max_connections',
    'Innodb_row_lock_current_waits',
    'Innodb_row_lock_waits',
    'Innodb_row_lock_time',
    'Innodb_deadlocks',
];

function observerEnvironment(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException(sprintf('Missing observer environment variable: %s', $name));
    }
    return $value;
}

/**
 * @param resource $stream
 */
function csvRow($stream, array $values): void
{
    if (fputcsv($stream, $values, ',', '"', '') === false || !fflush($stream)) {
        throw new RuntimeException('Observer CSV row could not be written.');
    }
}

function pathValue(array $value, string $path): mixed
{
    $current = $value;
    foreach (explode('.', $path) as $part) {
        if (!is_array($current) || !array_key_exists($part, $current)) return null;
        $current = $current[$part];
    }
    return $current;
}

/**
 * @return array<string, int|null>
 */
function mysqlStatus(PDO $connection): array
{
    $statement = $connection->query('SHOW GLOBAL STATUS');
    $values = array_fill_keys(MYSQL_STATUS_NAMES, null);

    while (($row = $statement->fetch()) !== false) {
        $name = (string) $row['Variable_name'];
        if (!array_key_exists($name, $values)) continue;
        $values[$name] = is_numeric($row['Value'])
            ? (int) $row['Value']
            : null;
    }

    return $values;
}

$runId = observerEnvironment('LOAD_TEST_RUN_ID');
$resultsRoot = rtrim(observerEnvironment('LOAD_TEST_RESULTS_ROOT'), '/');
$runDirectory = $resultsRoot . '/' . $runId;
$runtimeUrl = observerEnvironment('LOAD_TEST_RUNTIME_METRICS_URL');
$intervalMilliseconds = max(250, (int) observerEnvironment('LOAD_TEST_OBSERVER_INTERVAL_MS'));
$maximumSeconds = max(30, (int) observerEnvironment('LOAD_TEST_OBSERVER_MAX_SECONDS'));
$stopPath = $runDirectory . '/observer.stop';
$runtimePath = $runDirectory . '/runtime-metrics.csv';
$mysqlPath = $runDirectory . '/mysql-metrics.csv';

if (!is_dir($runDirectory)) {
    throw new RuntimeException('Observer run directory does not exist.');
}

$runtimeStream = fopen($runtimePath, 'wb');
$mysqlStream = fopen($mysqlPath, 'wb');

if ($runtimeStream === false || $mysqlStream === false) {
    throw new RuntimeException('Observer output files could not be opened.');
}

$runtimeFields = [
    'worker.id',
    'worker.status',
    'worker.initialized',
    'worker.uptimeSeconds',
    'server.connections_accepted',
    'server.connections_active',
    'server.connections_closed',
    'server.requests_total',
    'server.dispatch_total',
    'server.coroutine_num',
    'server.event_loop_lag_ms',
    'server.event_loop_lag_max_ms',
    'configuration.worker_num',
    'configuration.max_conn',
    'configuration.max_coroutine',
    'coroutines.active',
    'coroutines.peak',
    'webSocket.pendingConnections',
    'webSocket.authenticatedConnections',
    'webSocket.heartbeatSweeps',
    'webSocket.staleConnectionsClosed',
    'http.requestsObserved',
    'http.readinessFailures',
    'eventLoop.ticks',
    'eventLoop.currentLagMs',
    'eventLoop.maximumLagMs',
    'memory.usageBytes',
    'memory.peakUsageBytes',
];
csvRow($runtimeStream, array_merge(['timestamp'], $runtimeFields, ['observerError']));
csvRow($mysqlStream, array_merge(['timestamp', 'max_connections'], MYSQL_STATUS_NAMES, ['observerError']));

$environment = new Environment('/var/www/backend');
$database = new Database($environment);
$connection = $database->getConnection();
$maxConnections = null;

try {
    $statement = $connection->query("SHOW VARIABLES LIKE 'max_connections'");
    $row = $statement->fetch();
    $maxConnections = $row === false ? null : (int) $row['Value'];
} catch (Throwable) {
    // The CSV explicitly records NOT AVAILABLE through a blank value.
}

$startedAt = microtime(true);

try {
    while (!is_file($stopPath) && microtime(true) - $startedAt < $maximumSeconds) {
        $timestamp = gmdate('Y-m-d\TH:i:s') . sprintf('.%03dZ', (int) ((microtime(true) * 1000) % 1000));
        $runtimeError = '';
        $runtime = [];

        try {
            $context = stream_context_create([
                'http' => ['timeout' => 2, 'ignore_errors' => true],
            ]);
            $body = file_get_contents($runtimeUrl, false, $context);
            if (!is_string($body)) throw new RuntimeException('runtime endpoint unavailable');
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new RuntimeException('runtime payload invalid');
            $runtime = $decoded;
        } catch (Throwable $throwable) {
            $runtimeError = $throwable::class;
        }
        $runtimeValues = [$timestamp];
        foreach ($runtimeFields as $field) $runtimeValues[] = pathValue($runtime, $field);
        $runtimeValues[] = $runtimeError;
        csvRow($runtimeStream, $runtimeValues);

        $mysqlError = '';
        $status = array_fill_keys(MYSQL_STATUS_NAMES, null);
        try {
            $status = mysqlStatus($connection);
        } catch (Throwable $throwable) {
            $mysqlError = $throwable::class;
        }
        $mysqlValues = [$timestamp, $maxConnections];
        foreach (MYSQL_STATUS_NAMES as $name) $mysqlValues[] = $status[$name];
        $mysqlValues[] = $mysqlError;
        csvRow($mysqlStream, $mysqlValues);

        usleep($intervalMilliseconds * 1000);
    }
} finally {
    fclose($runtimeStream);
    fclose($mysqlStream);
}

echo "Runtime and MySQL observation completed.\n";
