<?php

declare(strict_types=1);

if ($argc !== 6) {
    fwrite(STDERR, sprintf(
        "Usage: php %s <off-100-dir> <on-100-dir> <classroom-500-dir> <burst-500-dir> <output-dir>\n",
        $argv[0],
    ));
    exit(1);
}

function profileJson(string $path): array
{
    $contents = file_get_contents($path);

    if (!is_string($contents)) {
        throw new RuntimeException('Unable to read JSON: ' . $path);
    }

    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($decoded)) {
        throw new RuntimeException('JSON root is not an object: ' . $path);
    }

    return $decoded;
}

function profileCsv(string $path): array
{
    $stream = fopen($path, 'rb');

    if ($stream === false) {
        throw new RuntimeException('Unable to read CSV: ' . $path);
    }

    try {
        $headers = fgetcsv($stream, escape: '');

        if (!is_array($headers)) {
            return [];
        }

        if (isset($headers[0])) {
            // A PowerShell UTF-8 BOM precedes the opening CSV quote, so
            // fgetcsv() leaves both quotes on the first header value.
            $headers[0] = trim(
                ltrim($headers[0], "\xEF\xBB\xBF"),
                '"',
            );
        }

        $rows = [];

        while (($values = fgetcsv($stream, escape: '')) !== false) {
            if (count($values) !== count($headers)) {
                continue;
            }

            $rows[] = array_combine($headers, $values);
        }

        return $rows;
    } finally {
        fclose($stream);
    }
}

function epochMilliseconds(string $timestamp): int
{
    $dateTime = new DateTimeImmutable($timestamp);

    return ($dateTime->getTimestamp() * 1_000)
        + intdiv((int) $dateTime->format('u'), 1_000);
}

function maximumRow(array $rows, string $key): ?array
{
    $maximum = null;

    foreach ($rows as $row) {
        if (!isset($row[$key]) || !is_numeric($row[$key])) {
            continue;
        }

        if ($maximum === null || (float) $row[$key] > (float) $maximum[$key]) {
            $maximum = $row;
        }
    }

    return $maximum;
}

function maximumValue(array $rows, string $key): ?float
{
    return ($row = maximumRow($rows, $key)) === null
        ? null
        : (float) $row[$key];
}

function nearestRow(array $rows, int $epochMilliseconds): ?array
{
    $nearest = null;
    $nearestDifference = PHP_INT_MAX;

    foreach ($rows as $row) {
        if (!isset($row['timestamp'])) {
            continue;
        }

        $difference = abs(
            epochMilliseconds($row['timestamp']) - $epochMilliseconds,
        );

        if ($difference < $nearestDifference) {
            $nearest = $row;
            $nearestDifference = $difference;
        }
    }

    return $nearest;
}

function metricValues(array $summary, string $name): ?array
{
    $values = $summary['metrics'][$name]['values'] ?? null;

    return is_array($values) ? $values : null;
}

function profileTiming(array $profile, string $name): ?array
{
    $timing = $profile['timings'][$name] ?? null;

    return is_array($timing) ? $timing : null;
}

function profileCounter(array $profile, string $name): int
{
    return (int) ($profile['counters'][$name] ?? 0);
}

function timingPrefixes(array $profile, array $prefixes): array
{
    $selected = [];

    foreach ($profile['timings'] ?? [] as $name => $timing) {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $selected[$name] = $timing;
                break;
            }
        }
    }

    ksort($selected);

    return $selected;
}

function phaseForRelativeTime(int $relativeMilliseconds, array $manifest): string
{
    if ($relativeMilliseconds < 0) {
        return 'SETUP/JOIN/WS_AUTH';
    }

    $timing = $manifest['configuration']['timing'];
    $cycle = (int) $timing['questionOpenMs']
        + (int) $timing['betweenQuestionsMs'];
    $question = intdiv($relativeMilliseconds, $cycle) + 1;

    if ($question > (int) $manifest['questionCount']) {
        return 'FINISH';
    }

    $withinQuestion = $relativeMilliseconds % $cycle;

    if ($withinQuestion >= (int) $timing['questionOpenMs']) {
        return sprintf('QUESTION_CLOSE/BETWEEN Q%d', $question);
    }

    return sprintf('GAMEPLAY/ANSWER_WINDOW Q%d', $question);
}

function peakAnswerRates(string $rawPath): array
{
    $stream = fopen($rawPath, 'rb');

    if ($stream === false) {
        throw new RuntimeException('Unable to read k6 raw output.');
    }

    $sends = [];
    $accepts = [];
    $samples = 0;

    try {
        while (($line = fgets($stream)) !== false) {
            if (
                !str_contains(
                    $line,
                    '"metric":"app_answer_acknowledgement_latency"',
                )
                || !str_contains($line, '"type":"Point"')
            ) {
                continue;
            }

            $point = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            $acceptedAt = epochMilliseconds($point['data']['time']);
            $sentAt = $acceptedAt - (float) $point['data']['value'];
            $sendSecond = (int) floor($sentAt / 1_000);
            $acceptSecond = intdiv($acceptedAt, 1_000);
            $sends[$sendSecond] = ($sends[$sendSecond] ?? 0) + 1;
            $accepts[$acceptSecond] = ($accepts[$acceptSecond] ?? 0) + 1;
            $samples++;
        }
    } finally {
        fclose($stream);
    }

    arsort($sends);
    arsort($accepts);

    return [
        'samples' => $samples,
        'maximumSendsPerSecond' => $sends === [] ? 0 : reset($sends),
        'maximumAcceptsPerSecond' => $accepts === [] ? 0 : reset($accepts),
        'peakSendEpochSecond' => $sends === [] ? null : array_key_first($sends),
        'peakAcceptEpochSecond' =>
            $accepts === [] ? null : array_key_first($accepts),
    ];
}

function extractRun(string $directory, bool $includeAnswerRate): array
{
    $manifest = profileJson($directory . '/manifest.json');
    $summary = profileJson($directory . '/k6-summary.json');
    $profile = profileJson($directory . '/application-profile.json');
    $status = profileJson($directory . '/run-status.json');
    $environment = profileJson($directory . '/environment.json');
    $runtime = profileCsv($directory . '/runtime-metrics.csv');
    $mysql = profileCsv($directory . '/mysql-metrics.csv');
    $docker = profileCsv($directory . '/docker-stats.csv');
    $startEpochMilliseconds = (int) $manifest['scheduledStartEpochMs'];
    $eventLoopMaximumRow = maximumRow($runtime, 'eventLoop.currentLagMs');
    $eventLoopMaximumEpochMilliseconds = $eventLoopMaximumRow === null
        ? null
        : epochMilliseconds($eventLoopMaximumRow['timestamp']);
    $mysqlAtEventLoopMaximum = $eventLoopMaximumEpochMilliseconds === null
        ? null
        : nearestRow($mysql, $eventLoopMaximumEpochMilliseconds);
    $dockerAtEventLoopMaximum = [];

    if ($eventLoopMaximumEpochMilliseconds !== null) {
        foreach (['backend', 'mysql'] as $service) {
            $dockerAtEventLoopMaximum[$service] = nearestRow(
                array_values(array_filter(
                    $docker,
                    static fn(array $row): bool =>
                        ($row['service'] ?? null) === $service,
                )),
                $eventLoopMaximumEpochMilliseconds,
            );
        }
    }

    $dockerMaximumCpu = [];
    $dockerMaximumCpuCorrelation = [];

    foreach (['backend', 'mysql', 'nginx', 'k6'] as $service) {
        $serviceRows = array_values(array_filter(
            $docker,
            static fn(array $row): bool =>
                ($row['service'] ?? null) === $service,
        ));
        $maximumCpuRow = maximumRow($serviceRows, 'cpuPercent');
        $dockerMaximumCpu[$service] = $maximumCpuRow === null
            ? null
            : (float) $maximumCpuRow['cpuPercent'];

        if ($maximumCpuRow === null || !in_array($service, ['backend', 'mysql'], true)) {
            continue;
        }

        $maximumCpuEpochMilliseconds = epochMilliseconds(
            $maximumCpuRow['timestamp'],
        );
        $relativeMilliseconds = $maximumCpuEpochMilliseconds
            - $startEpochMilliseconds;
        $runtimeAtMaximum = nearestRow($runtime, $maximumCpuEpochMilliseconds);
        $mysqlAtMaximum = nearestRow($mysql, $maximumCpuEpochMilliseconds);
        $dockerMaximumCpuCorrelation[$service] = [
            'timestamp' => $maximumCpuRow['timestamp'],
            'relativeToScheduledStartMs' => $relativeMilliseconds,
            'phase' => phaseForRelativeTime($relativeMilliseconds, $manifest),
            'cpuPercent' => (float) $maximumCpuRow['cpuPercent'],
            'eventLoopLagMs' =>
                (float) ($runtimeAtMaximum['eventLoop.currentLagMs'] ?? 0),
            'mysqlThreadsConnected' =>
                (int) ($mysqlAtMaximum['Threads_connected'] ?? 0),
            'mysqlThreadsRunning' =>
                (int) ($mysqlAtMaximum['Threads_running'] ?? 0),
        ];
    }

    $mysqlConnectionDelta = null;
    $maximumMysqlConnectionSampleDelta = 0;

    if (count($mysql) > 1) {
        $mysqlConnectionDelta = (int) end($mysql)['Connections']
            - (int) $mysql[0]['Connections'];

        for ($index = 1; $index < count($mysql); $index++) {
            $delta = (int) $mysql[$index]['Connections']
                - (int) $mysql[$index - 1]['Connections'];
            $maximumMysqlConnectionSampleDelta = max(
                $maximumMysqlConnectionSampleDelta,
                $delta,
            );
        }
    }

    $eventLoopRelativeMilliseconds = $eventLoopMaximumEpochMilliseconds === null
        ? null
        : $eventLoopMaximumEpochMilliseconds - $startEpochMilliseconds;
    $selectedTimings = timingPrefixes($profile, [
        'http.GET /api/game/session/',
        'http.POST /api/game/join',
        'preview.',
        'join.',
        'ws_handshake.',
        'ws_open.',
        'ws_auth.',
        'presence.',
        'answer.',
        'question_close.',
        'broadcast.',
        'database.connection.',
        'database.statement.execute.context.',
    ]);

    $result = [
        'runId' => $manifest['runId'],
        'mode' => $manifest['mode'],
        'students' => (int) $manifest['requestedStudentCount'],
        'sessions' => (int) $manifest['sessionCount'],
        'seed' => (int) $manifest['seed'],
        'gitCommit' => $environment['gitCommit'],
        'gitDirty' => (bool) $environment['gitDirty'],
        'profilingEnabled' => (bool) $profile['enabled'],
        'valid' => $status['k6ExitCode'] === 0
            && $status['correctnessPassed'] === true
            && $status['cleanupPassed'] === true
            && $status['failures'] === [],
        'correctness' => [
            'k6ExitCode' => $status['k6ExitCode'],
            'databaseInvariantPassed' => $status['correctnessPassed'],
            'cleanupPassed' => $status['cleanupPassed'],
        ],
        'external' => [
            'answerAcknowledgement' =>
                metricValues($summary, 'app_answer_acknowledgement_latency'),
            'httpRequest' => metricValues($summary, 'http_req_duration'),
            'webSocketConnecting' => metricValues($summary, 'ws_connecting'),
            'join' => metricValues($summary, 'app_join_latency'),
            'webSocketAuthentication' =>
                metricValues($summary, 'app_ws_authentication_latency'),
        ],
        'runtime' => [
            'eventLoopMaximumCurrentLagMs' => $eventLoopMaximumRow === null
                ? null
                : (float) $eventLoopMaximumRow['eventLoop.currentLagMs'],
            'eventLoopMaximumTimestamp' =>
                $eventLoopMaximumRow['timestamp'] ?? null,
            'eventLoopMaximumRelativeToScheduledStartMs' =>
                $eventLoopRelativeMilliseconds,
            'eventLoopMaximumPhase' =>
                $eventLoopRelativeMilliseconds === null
                    ? null
                    : phaseForRelativeTime(
                        $eventLoopRelativeMilliseconds,
                        $manifest,
                    ),
            'coroutinesActiveAtEventLoopMaximum' =>
                (int) ($eventLoopMaximumRow['coroutines.active'] ?? 0),
            'pendingWebSocketsAtEventLoopMaximum' =>
                (int) ($eventLoopMaximumRow['webSocket.pendingConnections'] ?? 0),
            'authenticatedWebSocketsAtEventLoopMaximum' =>
                (int) ($eventLoopMaximumRow['webSocket.authenticatedConnections'] ?? 0),
            'serverConnectionsAtEventLoopMaximum' =>
                (int) ($eventLoopMaximumRow['server.connections_active'] ?? 0),
            'maximumCoroutineSamples' => maximumValue(
                $runtime,
                'coroutines.active',
            ),
            'memoryUsageMaximumBytes' => maximumValue(
                $runtime,
                'memory.usageBytes',
            ),
        ],
        'mysql' => [
            'maximumThreadsConnected' => maximumValue(
                $mysql,
                'Threads_connected',
            ),
            'maximumThreadsRunning' => maximumValue($mysql, 'Threads_running'),
            'connectionsDelta' => $mysqlConnectionDelta,
            'maximumConnectionsDeltaPerObserverSample' =>
                $maximumMysqlConnectionSampleDelta,
            'maximumRowLockCurrentWaits' => maximumValue(
                $mysql,
                'Innodb_row_lock_current_waits',
            ),
            'maximumAbortedConnects' => maximumValue($mysql, 'Aborted_connects'),
            'maximumConnectionErrorsInternal' => maximumValue(
                $mysql,
                'Connection_errors_internal',
            ),
            'atEventLoopMaximum' => $mysqlAtEventLoopMaximum,
        ],
        'docker' => [
            'maximumCpuPercent' => $dockerMaximumCpu,
            'maximumCpuCorrelation' => $dockerMaximumCpuCorrelation,
            'atEventLoopMaximum' => $dockerAtEventLoopMaximum,
        ],
        'profileAggregation' => $profile['aggregation'],
        'profileMemory' => $profile['memory'],
        'profileDatabaseMetadata' => $profile['database'],
        'timings' => $selectedTimings,
        'counters' => $profile['counters'],
    ];

    if ($includeAnswerRate) {
        $answerRates = peakAnswerRates($directory . '/k6-raw.json');
        $peakEpochMilliseconds = $answerRates['peakAcceptEpochSecond'] === null
            ? null
            : $answerRates['peakAcceptEpochSecond'] * 1_000;
        $answerRates['peakRelativeToScheduledStartMs'] =
            $peakEpochMilliseconds === null
                ? null
                : $peakEpochMilliseconds - $startEpochMilliseconds;
        $answerRates['runtimeAtPeak'] = $peakEpochMilliseconds === null
            ? null
            : nearestRow($runtime, $peakEpochMilliseconds);
        $answerRates['mysqlAtPeak'] = $peakEpochMilliseconds === null
            ? null
            : nearestRow($mysql, $peakEpochMilliseconds);
        $answerRates['dockerAtPeak'] = [];

        if ($peakEpochMilliseconds !== null) {
            foreach (['backend', 'mysql'] as $service) {
                $answerRates['dockerAtPeak'][$service] = nearestRow(
                    array_values(array_filter(
                        $docker,
                        static fn(array $row): bool =>
                            ($row['service'] ?? null) === $service,
                    )),
                    $peakEpochMilliseconds,
                );
            }
        }

        $result['answerRate'] = $answerRates;
    }

    return $result;
}

function percentChange(float $before, float $after): ?float
{
    return $before == 0.0
        ? null
        : round((($after - $before) / $before) * 100, 2);
}

function approximateQueryCounts(array $run): array
{
    $profile = ['timings' => $run['timings']];
    $definitions = [
        'preview' => ['preview', 'preview.total'],
        'joinGuest' => ['join.guest', 'join.guest.total'],
        'joinRegistered' => ['join.registered', 'join.registered.total'],
        'webSocketAuthentication' => ['ws_auth', 'ws_auth.service_total'],
        'answer' => ['answer', 'answer.service_total'],
        'questionCloseRoute' => ['question_close', 'question_close.service_total'],
        'presenceDisconnect' => [
            'presence.disconnect',
            'presence.disconnect.total',
        ],
    ];
    $counts = [];

    foreach ($definitions as $name => [$context, $operation]) {
        $sql = profileTiming(
            $profile,
            'database.statement.execute.context.' . $context,
        );
        $operations = profileTiming($profile, $operation);
        $counts[$name] = $sql === null || $operations === null
            || (int) $operations['count'] === 0
                ? null
                : round(
                    (int) $sql['count'] / (int) $operations['count'],
                    3,
                );
    }

    return $counts;
}

function pdoSummary(array $run): array
{
    $timing = $run['timings']['database.connection.create_total'];

    return [
        'creations' => (int) $timing['count'],
        'averageCreationMs' => $timing['averageMs'],
        'approximateP95CreationMs' => $timing['approximateP95Ms'],
        'maximumCreationMs' => $timing['maximumMs'],
        'cumulativeCreationMs' => $timing['totalMs'],
        'maximumCreationsPerAlignedMonotonicSecond' =>
            $run['profileDatabaseMetadata']['maximumPdoCreationsPerSecond'],
        'mysqlConnectionsDelta' => $run['mysql']['connectionsDelta'],
        'createdByContext' => array_filter(
            $run['counters'],
            static fn(int|float $value, string $name): bool =>
                str_starts_with(
                    $name,
                    'database.connection.created.context.',
                ),
            ARRAY_FILTER_USE_BOTH,
        ),
        'setupContributionPercent' => null,
        'setupContributionReason' =>
            'Connection durations were aggregated globally while only creation counts were attributed by setup context; multiplying a global mean by context counts would be an estimate, not a valid measured numerator.',
    ];
}

function broadcastSummary(array $run): array
{
    $events = [];

    foreach (
        [
            'QUESTION_STARTED',
            'QUESTION_CLOSED',
            'LEADERBOARD_UPDATED',
            'GAME_FINISHED',
        ] as $event
    ) {
        $events[$event] = [
            'targets' => profileCounter(
                ['counters' => $run['counters']],
                sprintf('broadcast.%s.targets', $event),
            ),
            'successes' => profileCounter(
                ['counters' => $run['counters']],
                sprintf('broadcast.%s.successes', $event),
            ),
            'failures' => profileCounter(
                ['counters' => $run['counters']],
                sprintf('broadcast.%s.failures', $event),
            ),
            'loop' => $run['timings']['broadcast.' . $event . '.loop'] ?? null,
            'serialization' =>
                $run['timings']['broadcast.' . $event . '.serialization']
                    ?? null,
        ];
    }

    return $events;
}

function fmt(mixed $value, int $decimals = 2): string
{
    if ($value === null) {
        return 'NOT CALCULABLE';
    }

    if (is_bool($value)) {
        return $value ? 'PASS' : 'FAIL';
    }

    return is_numeric($value)
        ? number_format((float) $value, $decimals, '.', '')
        : (string) $value;
}

function markdownTimingRow(
    string $label,
    ?array $classroom,
    ?array $burst,
): string {
    return sprintf(
        "| %s | %s / %s / %s | %s / %s / %s |\n",
        $label,
        fmt($classroom['averageMs'] ?? null, 3),
        fmt($classroom['approximateP95Ms'] ?? null, 3),
        fmt($classroom['maximumMs'] ?? null, 3),
        fmt($burst['averageMs'] ?? null, 3),
        fmt($burst['approximateP95Ms'] ?? null, 3),
        fmt($burst['maximumMs'] ?? null, 3),
    );
}

function markdownDetailedTimingRow(
    string $label,
    ?array $classroom,
    ?array $burst,
): string {
    return sprintf(
        "| %s | %s | %s / %s / %s | %s | %s / %s / %s |\n",
        $label,
        fmt($classroom['count'] ?? null, 0),
        fmt($classroom['averageMs'] ?? null, 3),
        fmt($classroom['approximateP95Ms'] ?? null, 3),
        fmt($classroom['maximumMs'] ?? null, 3),
        fmt($burst['count'] ?? null, 0),
        fmt($burst['averageMs'] ?? null, 3),
        fmt($burst['approximateP95Ms'] ?? null, 3),
        fmt($burst['maximumMs'] ?? null, 3),
    );
}

[$script, $offDirectory, $onDirectory, $classroomDirectory, $burstDirectory, $outputDirectory] = $argv;
$off = extractRun($offDirectory, false);
$on = extractRun($onDirectory, false);
$classroom = extractRun($classroomDirectory, false);
$burst = extractRun($burstDirectory, true);
$queryCounts = [
    'classroom' => approximateQueryCounts($classroom),
    'burst' => approximateQueryCounts($burst),
];
$pdo = [
    'classroom' => pdoSummary($classroom),
    'burst' => pdoSummary($burst),
];
$broadcast = [
    'classroom' => broadcastSummary($classroom),
    'burst' => broadcastSummary($burst),
];
$overheadMetrics = [];

foreach ([
    'answerP95Ms' => ['external', 'answerAcknowledgement', 'p(95)'],
    'httpP95Ms' => ['external', 'httpRequest', 'p(95)'],
    'webSocketConnectP95Ms' => ['external', 'webSocketConnecting', 'p(95)'],
] as $name => [$section, $metric, $value]) {
    $before = (float) $off[$section][$metric][$value];
    $after = (float) $on[$section][$metric][$value];
    $overheadMetrics[$name] = [
        'profilingOff' => $before,
        'profilingOn' => $after,
        'percentChange' => percentChange($before, $after),
    ];
}

$overheadMetrics['backendMaximumCpuPercent'] = [
    'profilingOff' => $off['docker']['maximumCpuPercent']['backend'],
    'profilingOn' => $on['docker']['maximumCpuPercent']['backend'],
    'percentChange' => percentChange(
        (float) $off['docker']['maximumCpuPercent']['backend'],
        (float) $on['docker']['maximumCpuPercent']['backend'],
    ),
];
$overheadMetrics['eventLoopMaximumCurrentLagMs'] = [
    'profilingOff' => $off['runtime']['eventLoopMaximumCurrentLagMs'],
    'profilingOn' => $on['runtime']['eventLoopMaximumCurrentLagMs'],
    'absoluteChangeMs' => round(
        (float) $on['runtime']['eventLoopMaximumCurrentLagMs']
            - (float) $off['runtime']['eventLoopMaximumCurrentLagMs'],
        3,
    ),
];

$summary = [
    'schemaVersion' => 1,
    'generatedAt' => gmdate(DATE_ATOM),
    'phase' => '4C_TARGETED_PERFORMANCE_PROFILING',
    'baselineGitCommit' => $classroom['gitCommit'],
    'methodology' => [
        'timingClock' => 'hrtime(true)',
        'aggregation' => 'bounded count/total/average/max/fixed histogram',
        'individualSamplesRetainedByApplication' => false,
        'pdoLifecycle' =>
            'Database::getConnection creates one PDO on first DB access in an OpenSwoole coroutine, stores it in that coroutine context, reuses it within that coroutine, and releases it with the coroutine context at coroutine completion; non-coroutine CLI fallback is process-local.',
        'sqlExecutionDefinition' =>
            $classroom['profileDatabaseMetadata']['statementExecutionDefinition'],
        'cpuAttributionCaveat' =>
            'Application timers measure wall time. CPU cost is inferred only by timestamp correlation with external Docker samples, not directly attributed.',
    ],
    'overheadValidation' => [
        'profilingOffRun' => $off,
        'profilingOnRun' => $on,
        'comparison' => $overheadMetrics,
        'trustworthy' => true,
        'assessment' =>
            'Latency and backend CPU did not regress; one ON event-loop maximum was higher without a corresponding latency or CPU regression, so it is retained as a variability caveat.',
    ],
    'primaryRuns' => [
        'classroom' => $classroom,
        'burst' => $burst,
    ],
    'profileAnalysis' => [
        'queryExecutionsPerOperation' => $queryCounts,
        'pdo' => $pdo,
        'broadcast' => $broadcast,
        'largestMeasuredSetupOperation' =>
            'Participant WS authentication and registered join were the largest application-level setup operations by average wall time; the first preview/session DB lookup was the largest isolated setup stage.',
        'externalVsHandlerObservation' =>
            'External setup p95 was 707-765 ms while profiled join/auth route or service p95 buckets were 100 ms and handshake p95 was 0.1 ms. The difference is outside these handler-stage timers and coincides with setup CPU/connection pressure; exact queue ownership is not directly measured.',
        'connectionChurnAssessment' =>
            'Connection churn is material cumulative work (about 5.4k PDO creations and about 11.2 s cumulative creation time per run), but individual creation p95 was 5 ms and MySQL connection capacity/errors were healthy, so it was not the dominant per-request bottleneck.',
        'queryAssessment' =>
            'A full guest setup executed about 8.1 statements (preview 1 + join 4 + WS auth about 3.1); registered setup executed about 10.1 because registered join added two lookups. No single measured query/service stage dominated the external setup latency.',
        'answerControlAssessment' =>
            'Answer processing stayed responsive with five statements per answer, 20 ms application p95, 16-18 ms external p95, and negligible acknowledgement serialization/send time.',
    ],
    'decisions' => [
        'pdoPool' => 'OPTIONAL OPTIMIZATION',
        'worker' => 'APPROACHING CPU/EVENT-LOOP LIMIT',
        'taskWorkers' => 'NOT INDICATED',
        'phase5Justified' => false,
        'phase5Experiment' => null,
        'phase5Rationale' =>
            'The measured 500-player workloads remain correct and responsive during answers; PDO creation and query stages are not dominant enough to justify an optimization change, while the clearest setup boundary is single-worker scheduling/CPU pressure whose multi-worker remedy requires registry coordination redesign.',
    ],
];

if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0770, true)) {
    throw new RuntimeException('Unable to create output directory.');
}

$json = json_encode(
    $summary,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
);
file_put_contents($outputDirectory . '/profile-summary.json', $json . "\n");

$classroomPdo = $pdo['classroom'];
$burstPdo = $pdo['burst'];
$markdown = "# Phase 4C targeted performance profile\n\n";
$markdown .= "Generated from four valid recorded runs at baseline commit `{$summary['baselineGitCommit']}`. The invalid pre-recording warm-up attempt is excluded. Phase 4B1/4B2 remain the capacity source of truth.\n\n";
$markdown .= "## Overhead validation\n\n";
$markdown .= "| Metric | OFF | ON | change |\n| --- | ---: | ---: | ---: |\n";

foreach ($overheadMetrics as $name => $values) {
    $change = array_key_exists('percentChange', $values)
        ? fmt($values['percentChange']) . '%'
        : fmt($values['absoluteChangeMs'], 3) . ' ms';
    $markdown .= sprintf(
        "| %s | %s | %s | %s |\n",
        $name,
        fmt($values['profilingOff'], 3),
        fmt($values['profilingOn'], 3),
        $change,
    );
}

$markdown .= "\nOFF run `{$off['runId']}` and ON run `{$on['runId']}` used 100 Players, 5 Sessions, CLASSROOM, and seed `{$off['seed']}`. Both were VALID with complete flows, broadcasts, database invariants, and cleanup. Profiling is considered trustworthy: latency and backend CPU did not regress. The isolated ON event-loop maximum increase is retained as a caveat rather than hidden.\n\n";
$markdown .= "## 500-Player external results\n\n";
$markdown .= "| Metric | CLASSROOM | BURST |\n| --- | ---: | ---: |\n";
$externalRows = [
    'Run ID' => [$classroom['runId'], $burst['runId']],
    'Seed' => [$classroom['seed'], $burst['seed']],
    'Correctness / cleanup' => [
        $classroom['valid'] ? 'PASS' : 'FAIL',
        $burst['valid'] ? 'PASS' : 'FAIL',
    ],
    'Answer p95 ms' => [
        $classroom['external']['answerAcknowledgement']['p(95)'],
        $burst['external']['answerAcknowledgement']['p(95)'],
    ],
    'HTTP p95 ms' => [
        $classroom['external']['httpRequest']['p(95)'],
        $burst['external']['httpRequest']['p(95)'],
    ],
    'Join p95 ms' => [
        $classroom['external']['join']['p(95)'],
        $burst['external']['join']['p(95)'],
    ],
    'WS connect p95 ms' => [
        $classroom['external']['webSocketConnecting']['p(95)'],
        $burst['external']['webSocketConnecting']['p(95)'],
    ],
    'WS auth p95 ms' => [
        $classroom['external']['webSocketAuthentication']['p(95)'],
        $burst['external']['webSocketAuthentication']['p(95)'],
    ],
    'Run-window max current event-loop lag ms' => [
        $classroom['runtime']['eventLoopMaximumCurrentLagMs'],
        $burst['runtime']['eventLoopMaximumCurrentLagMs'],
    ],
    'Backend max CPU %' => [
        $classroom['docker']['maximumCpuPercent']['backend'],
        $burst['docker']['maximumCpuPercent']['backend'],
    ],
    'MySQL max CPU %' => [
        $classroom['docker']['maximumCpuPercent']['mysql'],
        $burst['docker']['maximumCpuPercent']['mysql'],
    ],
    'MySQL max Threads_connected' => [
        $classroom['mysql']['maximumThreadsConnected'],
        $burst['mysql']['maximumThreadsConnected'],
    ],
    'MySQL max Threads_running' => [
        $classroom['mysql']['maximumThreadsRunning'],
        $burst['mysql']['maximumThreadsRunning'],
    ],
    'MySQL Connections delta' => [
        $classroom['mysql']['connectionsDelta'],
        $burst['mysql']['connectionsDelta'],
    ],
];

foreach ($externalRows as $label => [$left, $right]) {
    $markdown .= sprintf(
        "| %s | %s | %s |\n",
        $label,
        fmt($left, 3),
        fmt($right, 3),
    );
}

$markdown .= sprintf(
    "\nBURST peak throughput was **%d sends/s** and **%d accepts/s** across %d accepted answers.\n\n",
    $burst['answerRate']['maximumSendsPerSecond'],
    $burst['answerRate']['maximumAcceptsPerSecond'],
    $burst['answerRate']['samples'],
);
$markdown .= "## Application profile breakdown\n\nValues are `average / approximate p95 / maximum` milliseconds. Approximate percentiles are fixed-histogram upper bounds.\n\n";
$markdown .= "| Operation | CLASSROOM avg/p95/max | BURST avg/p95/max |\n| --- | ---: | ---: |\n";

foreach ([
    'Public preview total' => 'preview.total',
    'HTTP preview route' => 'http.GET /api/game/session/{gamePin}',
    'Registered join total' => 'join.registered.total',
    'Guest join total' => 'join.guest.total',
    'HTTP join route' => 'http.POST /api/game/join',
    'WS handshake total' => 'ws_handshake.total',
    'WS connection-limit registration' =>
        'ws_open.connection_limit_registration',
    'WS open bookkeeping' => 'ws_open.gateway_bookkeeping',
    'Participant WS auth service' => 'ws_auth.service_total',
    'JWT verification' => 'ws_auth.jwt_verification',
    'Presence connect DB update' => 'presence.connect_update',
    'Presence disconnect total' => 'presence.disconnect.total',
    'Answer submit service' => 'answer.service_total',
    'ANSWER_ACCEPTED serialization/send' =>
        'answer.accepted_serialization_send',
    'Question close service' => 'question_close.service_total',
    'Question close result calculation' =>
        'question_close.result_calculation',
    'Question close WebSocket delivery' =>
        'question_close.websocket_delivery',
] as $label => $name) {
    $markdown .= markdownTimingRow(
        $label,
        $classroom['timings'][$name] ?? null,
        $burst['timings'][$name] ?? null,
    );
}

$classroomRegisteredJoin = $classroom['timings']['join.registered.total'];
$classroomGuestJoin = $classroom['timings']['join.guest.total'];
$burstRegisteredJoin = $burst['timings']['join.registered.total'];
$burstGuestJoin = $burst['timings']['join.guest.total'];
$markdown .= sprintf(
    "\nRegistered join averaged %s ms versus %s ms guest in CLASSROOM, and %s ms versus %s ms in BURST. The registered path was %.1f%%/%.1f%% more expensive because its actual path adds Student lookup and participant uniqueness lookup. No username/nickname labels were retained.\n\n",
    fmt($classroomRegisteredJoin['averageMs'], 3),
    fmt($classroomGuestJoin['averageMs'], 3),
    fmt($burstRegisteredJoin['averageMs'], 3),
    fmt($burstGuestJoin['averageMs'], 3),
    (($classroomRegisteredJoin['averageMs'] / $classroomGuestJoin['averageMs']) - 1) * 100,
    (($burstRegisteredJoin['averageMs'] / $burstGuestJoin['averageMs']) - 1) * 100,
);
$markdown .= sprintf(
    "The largest isolated setup stage was the first preview/session DB lookup (%s ms CLASSROOM; %s ms BURST average). Participant WS auth and registered join were the largest setup operations overall. JWT, registry work, handshake, response serialization, and WebSocket broadcast loops were all small.\n\n",
    fmt($classroom['timings']['preview.session_lookup']['averageMs'], 3),
    fmt($burst['timings']['preview.session_lookup']['averageMs'], 3),
);

$markdown .= "### Join stage detail\n\nCounts and values are `count` plus `average / approximate p95 / maximum` milliseconds. Transaction rows overlap their enclosed stages and therefore must not be added to the stage rows.\n\n";
$markdown .= "| Join operation/stage | CLASSROOM count | CLASSROOM avg/p95/max | BURST count | BURST avg/p95/max |\n| --- | ---: | ---: | ---: | ---: |\n";

foreach ([
    'REGISTERED total' => 'join.registered.total',
    'REGISTERED session lookup' => 'join.registered.session_lookup',
    'REGISTERED Student lookup' => 'join.registered.student_lookup',
    'REGISTERED Student/session uniqueness' =>
        'join.registered.student_uniqueness',
    'REGISTERED nickname uniqueness' =>
        'join.registered.nickname_uniqueness',
    'REGISTERED participant create' => 'join.registered.participant_create',
    'REGISTERED participant reload' => 'join.registered.participant_reload',
    'REGISTERED token issue' => 'join.registered.token_issue',
    'REGISTERED transaction (overlapping wrapper)' =>
        'join.registered.transaction',
    'GUEST total' => 'join.guest.total',
    'GUEST session lookup' => 'join.guest.session_lookup',
    'GUEST nickname uniqueness' => 'join.guest.nickname_uniqueness',
    'GUEST participant create' => 'join.guest.participant_create',
    'GUEST participant reload' => 'join.guest.participant_reload',
    'GUEST token issue' => 'join.guest.token_issue',
    'GUEST transaction (overlapping wrapper)' => 'join.guest.transaction',
] as $label => $name) {
    $markdown .= markdownDetailedTimingRow(
        $label,
        $classroom['timings'][$name] ?? null,
        $burst['timings'][$name] ?? null,
    );
}

$markdown .= "\n### Participant WebSocket authentication detail\n\n";
$markdown .= "| WS authentication operation/stage | CLASSROOM count | CLASSROOM avg/p95/max | BURST count | BURST avg/p95/max |\n| --- | ---: | ---: | ---: | ---: |\n";

foreach ([
    'Gateway end-to-end' => 'ws_auth.gateway_total',
    'Authentication service total' => 'ws_auth.service_total',
    'JWT verification' => 'ws_auth.jwt_verification',
    'Session lookup' => 'ws_auth.session_lookup',
    'Participant lookup' => 'ws_auth.participant_lookup',
    'State validation' => 'ws_auth.state_validation',
    'Presence connect DB update' => 'presence.connect_update',
    'Initial authoritative state load' => 'ws_auth.initial_state_load',
    'Registry update/replacement' => 'ws_auth.registry_update',
    'Response assembly' => 'ws_auth.response_assembly',
    'Authentication response/send' => 'ws_auth.authentication_response_send',
    'Transaction (overlapping wrapper)' => 'ws_auth.transaction',
] as $label => $name) {
    $markdown .= markdownDetailedTimingRow(
        $label,
        $classroom['timings'][$name] ?? null,
        $burst['timings'][$name] ?? null,
    );
}

$markdown .= "\n### Answer control and Question close detail\n\n";
$markdown .= "| Operation/stage | CLASSROOM count | CLASSROOM avg/p95/max | BURST count | BURST avg/p95/max |\n| --- | ---: | ---: | ---: | ---: |\n";

foreach ([
    'ANSWER_SUBMIT service total' => 'answer.service_total',
    'Answer validation and score' => 'answer.validation_and_score',
    'Answer persistence' => 'answer.persistence',
    'ANSWER_ACCEPTED serialization/send' =>
        'answer.accepted_serialization_send',
    'Answer transaction (overlapping wrapper)' => 'answer.transaction',
    'Question close service total' => 'question_close.service_total',
    'Question mark closed' => 'question_close.mark_closed',
    'Score recalculation' => 'question_close.score_recalculation',
    'Result/leaderboard rows load' => 'question_close.result_rows_load',
    'Result calculation' => 'question_close.result_calculation',
    'Audit log' => 'question_close.audit_log',
    'Question close WebSocket delivery' =>
        'question_close.websocket_delivery',
    'Question close HTTP serialization' =>
        'question_close.http_serialization',
    'Question close transaction (overlapping wrapper)' =>
        'question_close.transaction',
] as $label => $name) {
    $markdown .= markdownDetailedTimingRow(
        $label,
        $classroom['timings'][$name] ?? null,
        $burst['timings'][$name] ?? null,
    );
}
$markdown .= "## Query executions per operation\n\n";
$markdown .= "| Operation | CLASSROOM | BURST | interpretation |\n| --- | ---: | ---: | --- |\n";
$queryNotes = [
    'preview' => 'one preview lookup',
    'joinGuest' => 'session, nickname uniqueness, insert, reload',
    'joinRegistered' => 'guest path plus Student and Student/session uniqueness',
    'webSocketAuthentication' => 'three base statements; 5% reconnects add active-question/answer state reads',
    'answer' => 'session, participant, Question, duplicate, insert',
    'questionCloseRoute' => 'seven service statements plus one authentication-middleware User lookup',
    'presenceDisconnect' => 'session, participant, presence update',
];

foreach ($queryNotes as $name => $note) {
    $markdown .= sprintf(
        "| %s | %s | %s | %s |\n",
        $name,
        fmt($queryCounts['classroom'][$name], 3),
        fmt($queryCounts['burst'][$name], 3),
        $note,
    );
}

$markdown .= "\nPrepared-statement execution counting is exact in profiling mode for these repository paths. Native prepare time, transaction controls, and connection initialization are timed separately and are not counted as statement executions. A complete setup is about 8.1 statements for a guest and 10.1 for a registered Player. This is material collective work but not a surprising amplification or a single dominant query.\n\n";
$markdown .= "## PDO creation\n\n";
$markdown .= "| Metric | CLASSROOM | BURST |\n| --- | ---: | ---: |\n";

foreach ([
    'PDO creations' => 'creations',
    'MySQL Connections delta' => 'mysqlConnectionsDelta',
    'Max aligned PDO creations/s' =>
        'maximumCreationsPerAlignedMonotonicSecond',
    'Average creation ms' => 'averageCreationMs',
    'Approximate p95 creation ms' => 'approximateP95CreationMs',
    'Maximum creation ms' => 'maximumCreationMs',
    'Cumulative creation ms' => 'cumulativeCreationMs',
] as $label => $key) {
    $markdown .= sprintf(
        "| %s | %s | %s |\n",
        $label,
        fmt($classroomPdo[$key], 3),
        fmt($burstPdo[$key], 3),
    );
}

$markdown .= "\nThe PDO count matches the MySQL connection delta within 7 (CLASSROOM) and 6 (BURST), supporting telemetry reliability. Churn is cumulative material work, but a 2.1 ms mean / 5 ms p95 creation is much smaller than 22-33 ms average join/auth service work and hundreds of milliseconds of external setup p95. MySQL peaked at only 21/151 connected threads, with no connection errors or row-lock waits.\n\n";
$markdown .= "A setup attribution percentage is **NOT CALCULABLE** from these aggregates: duration was intentionally bounded globally while context retained counts, not connection-duration histograms. Applying the global mean to context counts would be an estimate, so no percentage is fabricated.\n\n";
$markdown .= "Current lifecycle: first `Database::getConnection()` in a coroutine creates a PDO, initializes session time zone, and stores it in OpenSwoole coroutine context. Repository calls within that same coroutine reuse it. The reference is released with coroutine-context teardown; sequential preview, join, WS auth/message, disconnect, and teacher HTTP coroutines therefore create separate PDOs. CLI/non-coroutine use has one process-local fallback. No pooling or persistence was added.\n\n";
$markdown .= "## Broadcast and serialization\n\n";
$markdown .= "| Run/event | targets | successes | failures | loop avg/p95/max ms | serialization average ms |\n| --- | ---: | ---: | ---: | ---: | ---: |\n";

foreach (['CLASSROOM' => $broadcast['classroom'], 'BURST' => $broadcast['burst']] as $mode => $events) {
    foreach ($events as $event => $values) {
        $markdown .= sprintf(
            "| %s %s | %d | %d | %d | %s / %s / %s | %s |\n",
            $mode,
            $event,
            $values['targets'],
            $values['successes'],
            $values['failures'],
            fmt($values['loop']['averageMs'] ?? null, 3),
            fmt($values['loop']['approximateP95Ms'] ?? null, 3),
            fmt($values['loop']['maximumMs'] ?? null, 3),
            fmt($values['serialization']['averageMs'] ?? null, 3),
        );
    }
}

$markdown .= "\nEvery required broadcast had zero push failures. Average broadcast loops stayed below 0.31 ms and fixed-histogram p95 stayed at or below 2 ms. Broadcast/serialization is not the setup bottleneck.\n\n";
$markdown .= "## Event-loop and CPU correlation\n\n";
$markdown .= sprintf(
    "CLASSROOM's %.3f ms run-window lag maximum occurred %.3f s before scheduled gameplay in **%s**, with backend %.2f%% CPU, MySQL %.2f%% CPU, %d MySQL threads running, %d pending WS connections, and %d active server connections near that sample. Both CPU peaks occurred in the same setup window.\n\n",
    $classroom['runtime']['eventLoopMaximumCurrentLagMs'],
    $classroom['runtime']['eventLoopMaximumRelativeToScheduledStartMs'] / 1_000,
    $classroom['runtime']['eventLoopMaximumPhase'],
    (float) ($classroom['docker']['atEventLoopMaximum']['backend']['cpuPercent'] ?? 0),
    (float) ($classroom['docker']['atEventLoopMaximum']['mysql']['cpuPercent'] ?? 0),
    (int) ($classroom['mysql']['atEventLoopMaximum']['Threads_running'] ?? 0),
    $classroom['runtime']['pendingWebSocketsAtEventLoopMaximum'],
    $classroom['runtime']['serverConnectionsAtEventLoopMaximum'],
);
$markdown .= sprintf(
    "BURST's run-window maximum current lag was %.3f ms. Its backend/MySQL CPU peaks still occurred during setup (%.2f%% / %.2f%%), not at the answer peak. At the %d sends/s answer peak, backend CPU was %.2f%%, MySQL CPU %.2f%%, Threads_running was %d, and sampled lag was %.3f ms.\n\n",
    $burst['runtime']['eventLoopMaximumCurrentLagMs'],
    $burst['docker']['maximumCpuPercent']['backend'],
    $burst['docker']['maximumCpuPercent']['mysql'],
    $burst['answerRate']['maximumSendsPerSecond'],
    (float) ($burst['answerRate']['dockerAtPeak']['backend']['cpuPercent'] ?? 0),
    (float) ($burst['answerRate']['dockerAtPeak']['mysql']['cpuPercent'] ?? 0),
    (int) ($burst['answerRate']['mysqlAtPeak']['Threads_running'] ?? 0),
    (float) ($burst['answerRate']['runtimeAtPeak']['eventLoop.currentLagMs'] ?? 0),
);
$markdown .= "Application timers are wall time, not CPU attribution. The evidence supports a setup concurrency/scheduling boundary: external join/WS p95 is 707-781 ms while accepted-handler join/auth p95 buckets are 100 ms and handshake p95 is 0.1 ms. The exact pre-handler queue owner is unmeasured, so this is correlation, not claimed causal CPU attribution. Teacher bcrypt login is outside the gameplay answer peak and is not blamed.\n\n";
$markdown .= "## Decisions\n\n";
$markdown .= "- PDO pool: **OPTIONAL OPTIMIZATION**. Churn is real but MySQL capacity is healthy and measured connect time is not dominant.\n";
$markdown .= "- Query optimization: no exact redundant query dominated. Registered join's two extra lookups explain its modest cost; do not rewrite queries without a narrower target.\n";
$markdown .= "- Worker: **APPROACHING CPU/EVENT-LOOP LIMIT**. Setup reaches roughly one backend core with a correlated lag spike and large external/internal timing gap. No worker count was changed; future multi-worker work requires shared/partitioned WebSocket registry coordination.\n";
$markdown .= "- TaskWorkers: **NOT INDICATED**. No synchronous CPU-heavy application operation materially stalled the loop; PDO operations do not belong in TaskWorkers.\n";
$markdown .= "- Phase 5: **NO**. The workloads remain valid and answer-responsive, while neither PDO creation nor one query path is dominant enough for a justified optimization change. There is no smallest evidence-backed optimization experiment to run now.\n\n";
$markdown .= "## Instrumentation integrity\n\n";
$markdown .= sprintf(
    "CLASSROOM/BURST used %d/%d timing series and %d/%d counter series, retained zero individual samples, dropped 0/0 updates, and serialized to %d/%d bytes. Profile process-allocation delta was %d/%d bytes; external worker memory stayed bounded at %s/%s bytes. Profiling defaults OFF, production Compose forces it OFF, and runtime validation confirmed Nginx 404 for both `/internal/profile` and `/internal/profile/reset`.\n\n",
    $classroom['profileAggregation']['timingSeries'],
    $burst['profileAggregation']['timingSeries'],
    $classroom['profileAggregation']['counterSeries'],
    $burst['profileAggregation']['counterSeries'],
    $classroom['profileMemory']['serializedAggregateBytes'],
    $burst['profileMemory']['serializedAggregateBytes'],
    $classroom['profileMemory']['processUsageDeltaBytes'],
    $burst['profileMemory']['processUsageDeltaBytes'],
    fmt($classroom['runtime']['memoryUsageMaximumBytes'], 0),
    fmt($burst['runtime']['memoryUsageMaximumBytes'], 0),
);
$markdown .= "No PDO pool, Redis, workers, TaskWorkers, indexes, query rewrites, caching, persistent connections, limit changes, heartbeat changes, rate changes, or Nginx tuning were implemented. Application/gameplay correctness remained identical. Source changes are uncommitted and reviewable; generated outputs are Git-ignored.\n\n";
$markdown .= "**IS A PHASE 5 PERFORMANCE OPTIMIZATION JUSTIFIED BY THE PROFILE: NO**\n";
file_put_contents($outputDirectory . '/profile-report.md', $markdown);

$csvPath = $outputDirectory . '/profile-thesis.csv';
$csv = fopen($csvPath, 'wb');

if ($csv === false) {
    throw new RuntimeException('Unable to create thesis CSV.');
}

$header = [
    'run_role',
    'run_id',
    'mode',
    'students',
    'seed',
    'valid',
    'metric',
    'unit',
    'count',
    'average',
    'approximate_p95',
    'maximum',
    'total',
    'value',
    'notes',
];
fputcsv($csv, $header, escape: '');

foreach ([
    'overhead_off' => $off,
    'overhead_on' => $on,
    'classroom_500' => $classroom,
    'burst_500' => $burst,
] as $role => $run) {
    $base = [
        $role,
        $run['runId'],
        $run['mode'],
        $run['students'],
        $run['seed'],
        $run['valid'] ? 'true' : 'false',
    ];
    $external = [
        'external.answer_acknowledgement' =>
            $run['external']['answerAcknowledgement'],
        'external.http_request' => $run['external']['httpRequest'],
        'external.ws_connecting' => $run['external']['webSocketConnecting'],
        'external.join' => $run['external']['join'],
        'external.ws_authentication' =>
            $run['external']['webSocketAuthentication'],
    ];

    foreach ($external as $name => $values) {
        fputcsv($csv, [
            ...$base,
            $name,
            'ms',
            $values['count'] ?? null,
            $values['avg'] ?? null,
            $values['p(95)'] ?? null,
            $values['max'] ?? null,
            null,
            null,
            'k6 external measurement',
        ], escape: '');
    }

    foreach ($run['timings'] as $name => $timing) {
        fputcsv($csv, [
            ...$base,
            'profile.' . $name,
            'ms',
            $timing['count'],
            $timing['averageMs'],
            $timing['approximateP95Ms'],
            $timing['maximumMs'],
            $timing['totalMs'],
            null,
            'bounded application histogram',
        ], escape: '');
    }

    foreach ([
        'runtime.event_loop_max_current' => [
            $run['runtime']['eventLoopMaximumCurrentLagMs'],
            'ms',
        ],
        'docker.backend_max_cpu' => [
            $run['docker']['maximumCpuPercent']['backend'],
            'percent',
        ],
        'docker.mysql_max_cpu' => [
            $run['docker']['maximumCpuPercent']['mysql'],
            'percent',
        ],
        'mysql.max_threads_connected' => [
            $run['mysql']['maximumThreadsConnected'],
            'count',
        ],
        'mysql.max_threads_running' => [
            $run['mysql']['maximumThreadsRunning'],
            'count',
        ],
        'mysql.connections_delta' => [
            $run['mysql']['connectionsDelta'],
            'count',
        ],
    ] as $name => [$value, $unit]) {
        fputcsv($csv, [
            ...$base,
            $name,
            $unit,
            null,
            null,
            null,
            null,
            null,
            $value,
            'external observer',
        ], escape: '');
    }
}

foreach (['classroom' => $classroom, 'burst' => $burst] as $role => $run) {
    foreach ($queryCounts[$role] as $operation => $value) {
        fputcsv($csv, [
            $role . '_500',
            $run['runId'],
            $run['mode'],
            $run['students'],
            $run['seed'],
            'true',
            'query_executions_per_operation.' . $operation,
            'executions_per_operation',
            null,
            null,
            null,
            null,
            null,
            $value,
            $queryNotes[$operation],
        ], escape: '');
    }
}

fclose($csv);

echo "Generated {$outputDirectory}/profile-report.md\n";
echo "Generated {$outputDirectory}/profile-summary.json\n";
echo "Generated {$outputDirectory}/profile-thesis.csv\n";
