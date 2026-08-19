<?php

declare(strict_types=1);

use CodeLandQuiz\Observability\PerformanceProfiler;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertProfile(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$disabled = new PerformanceProfiler(false);
$disabled->increment('disabled.counter');
$disabled->recordDuration('disabled.timing', hrtime(true));
$disabledSnapshot = $disabled->snapshot();

assertProfile($disabledSnapshot['enabled'] === false, 'Disabled mode changed.');
assertProfile($disabledSnapshot['timings'] === [], 'Disabled timings were retained.');
assertProfile($disabledSnapshot['counters'] === [], 'Disabled counters were retained.');

$enabled = new PerformanceProfiler(true);
$enabled->inContext('join.registered', function () use ($enabled): void {
    $enabled->recordDatabaseConnectionRequested();
    $startedAt = hrtime(true);
    $enabled->recordSqlExecution($startedAt);
    $enabled->recordPdoCreation($startedAt);
});
$snapshot = $enabled->snapshot();

assertProfile($snapshot['enabled'] === true, 'Enabled mode changed.');
assertProfile(
    ($snapshot['timings']['database.connection.create_total']['count'] ?? 0)
        === 1,
    'PDO creation was not aggregated.',
);
assertProfile(
    ($snapshot['timings']['database.statement.execute.context.join.registered']['count'] ?? 0)
        === 1,
    'SQL execution was not attributed to its bounded context.',
);
assertProfile(
    ($snapshot['counters']['database.connection.created.context.join.registered'] ?? 0)
        === 1,
    'PDO creation context was not counted.',
);

for ($index = 0; $index < 250; $index++) {
    $enabled->recordDuration('bounded.' . $index, hrtime(true));
    $enabled->increment('bounded.' . $index);
}

$boundedSnapshot = $enabled->snapshot();
assertProfile(
    $boundedSnapshot['aggregation']['timingSeries']
        <= $boundedSnapshot['aggregation']['maximumTimingSeries'],
    'Timing aggregation exceeded its fixed series cap.',
);
assertProfile(
    $boundedSnapshot['aggregation']['counterSeries']
        <= $boundedSnapshot['aggregation']['maximumCounterSeries'],
    'Counter aggregation exceeded its fixed series cap.',
);
assertProfile(
    $boundedSnapshot['aggregation']['droppedTimingSamples'] > 0
        && $boundedSnapshot['aggregation']['droppedCounterUpdates'] > 0,
    'Series overflow was not rejected.',
);

$enabled->reset();
$resetSnapshot = $enabled->snapshot();
assertProfile($resetSnapshot['timings'] === [], 'Reset retained timings.');
assertProfile($resetSnapshot['counters'] === [], 'Reset retained counters.');

$repositoryRoot = dirname(__DIR__, 2);
$productionCompose = file_get_contents(
    $repositoryRoot . '/compose.production.yaml',
);
$loadCompose = file_get_contents(
    $repositoryRoot . '/load-testing/compose.load-test.yaml',
);
$nginxTemplate = file_get_contents(
    $repositoryRoot . '/docker/nginx/default.conf.template',
);
$runner = file_get_contents(
    $repositoryRoot . '/load-testing/scripts/run-load-test.ps1',
);

assertProfile(
    is_string($productionCompose)
        && str_contains(
            $productionCompose,
            'PERFORMANCE_PROFILING_ENABLED: "false"',
        ),
    'Production Compose does not force profiling off.',
);
assertProfile(
    is_string($loadCompose)
        && str_contains($loadCompose, 'PERFORMANCE_PROFILING_ENABLED:'),
    'Load-test Compose cannot opt into profiling.',
);
assertProfile(
    is_string($nginxTemplate)
        && str_contains($nginxTemplate, 'location = /internal/profile {')
        && str_contains(
            $nginxTemplate,
            'location = /internal/profile/reset {',
        ),
    'Nginx does not explicitly hide both profile routes.',
);
assertProfile(
    is_string($runner)
        && str_contains($runner, '/internal/profile/reset')
        && str_contains($runner, '/internal/profile'),
    'The load runner does not reset and capture the private profile.',
);

echo "Performance profiling verification passed.\n";
