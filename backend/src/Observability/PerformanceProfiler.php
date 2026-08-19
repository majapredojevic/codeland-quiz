<?php

declare(strict_types=1);

namespace CodeLandQuiz\Observability;

use OpenSwoole\Coroutine;

final class PerformanceProfiler
{
    private const COROUTINE_CONTEXT_KEY =
        'codeland_quiz.performance_profile.context';

    private const MAX_TIMING_SERIES = 192;
    private const MAX_COUNTER_SERIES = 192;

    /**
     * Fixed upper bounds in microseconds. Samples above the final bound use
     * one overflow bucket and approximate percentiles fall back to max.
     *
     * @var int[]
     */
    private const HISTOGRAM_UPPER_BOUNDS_US = [
        50,
        100,
        250,
        500,
        1_000,
        2_000,
        5_000,
        10_000,
        20_000,
        50_000,
        100_000,
        250_000,
        500_000,
        1_000_000,
        2_500_000,
        5_000_000,
    ];

    /**
     * @var string[]
     */
    private const SQL_CONTEXTS = [
        'preview',
        'join.registered',
        'join.guest',
        'ws_auth',
        'presence.disconnect',
        'answer',
        'question_close',
        'other',
    ];

    /**
     * @var array<string, array{
     *     count: int,
     *     totalUs: int,
     *     maxUs: int,
     *     buckets: int[]
     * }>
     */
    private array $timings = [];

    /**
     * @var array<string, int>
     */
    private array $counters = [];

    private int $droppedTimingSamples = 0;
    private int $droppedCounterUpdates = 0;
    private int $resetAtMonotonicNanoseconds = 0;
    private int $memoryUsageBytesAtReset = 0;
    private int $pdoCreationCurrentSecond = -1;
    private int $pdoCreationsInCurrentSecond = 0;
    private int $maximumPdoCreationsPerSecond = 0;
    private ?string $fallbackContext = null;

    public function __construct(
        private readonly bool $enabled,
    ) {
        $this->reset();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function start(): int
    {
        return hrtime(true);
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function measure(string $name, callable $operation): mixed
    {
        $startedAt = hrtime(true);

        try {
            return $operation();
        } finally {
            $this->recordDuration($name, $startedAt);
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function inContext(string $contextName, callable $operation): mixed
    {
        $previousContext = $this->currentContext();
        $this->setCurrentContext($contextName);

        try {
            return $operation();
        } finally {
            $this->setCurrentContext($previousContext);
        }
    }

    public function recordDuration(string $name, int $startedAt): void
    {
        if (!$this->enabled || $startedAt < 1) {
            return;
        }

        $elapsedNanoseconds = max(0, hrtime(true) - $startedAt);
        $this->recordMicroseconds(
            $name,
            intdiv($elapsedNanoseconds + 500, 1_000),
        );
    }

    public function increment(string $name, int $amount = 1): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!array_key_exists($name, $this->counters)) {
            if (count($this->counters) >= self::MAX_COUNTER_SERIES) {
                $this->droppedCounterUpdates++;

                return;
            }

            $this->counters[$name] = 0;
        }

        $this->counters[$name] += $amount;
    }

    public function recordDatabaseConnectionRequested(): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->increment('database.connection.requested');
        $this->increment(sprintf(
            'database.connection.requested.context.%s',
            $this->sqlContext(),
        ));
    }

    public function recordPdoCreation(int $startedAt): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->recordDuration('database.connection.create_total', $startedAt);
        $this->increment(sprintf(
            'database.connection.created.context.%s',
            $this->sqlContext(),
        ));

        $second = intdiv(hrtime(true), 1_000_000_000);

        if ($second !== $this->pdoCreationCurrentSecond) {
            $this->maximumPdoCreationsPerSecond = max(
                $this->maximumPdoCreationsPerSecond,
                $this->pdoCreationsInCurrentSecond,
            );
            $this->pdoCreationCurrentSecond = $second;
            $this->pdoCreationsInCurrentSecond = 0;
        }

        $this->pdoCreationsInCurrentSecond++;
        $this->maximumPdoCreationsPerSecond = max(
            $this->maximumPdoCreationsPerSecond,
            $this->pdoCreationsInCurrentSecond,
        );
    }

    public function recordSqlExecution(int $startedAt): void
    {
        if (!$this->enabled) {
            return;
        }

        $elapsedNanoseconds = max(0, hrtime(true) - $startedAt);
        $elapsedMicroseconds = intdiv($elapsedNanoseconds + 500, 1_000);
        $this->recordMicroseconds(
            'database.statement.execute',
            $elapsedMicroseconds,
        );
        $this->recordMicroseconds(
            sprintf('database.statement.execute.context.%s', $this->sqlContext()),
            $elapsedMicroseconds,
        );
    }

    public function recordTransactionControl(
        string $control,
        int $startedAt,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $this->recordDuration(
            sprintf('database.transaction.%s', $control),
            $startedAt,
        );
        $this->increment(sprintf(
            'database.transaction.%s.context.%s',
            $control,
            $this->sqlContext(),
        ));
    }

    public function currentContext(): ?string
    {
        if (Coroutine::getCid() >= 0) {
            $context = Coroutine::getContext();
            $value = $context[self::COROUTINE_CONTEXT_KEY] ?? null;

            return is_string($value) ? $value : null;
        }

        return $this->fallbackContext;
    }

    public function reset(): void
    {
        $this->timings = [];
        $this->counters = [];
        $this->droppedTimingSamples = 0;
        $this->droppedCounterUpdates = 0;
        $this->resetAtMonotonicNanoseconds = hrtime(true);
        $this->pdoCreationCurrentSecond = -1;
        $this->pdoCreationsInCurrentSecond = 0;
        $this->maximumPdoCreationsPerSecond = 0;
        $this->fallbackContext = null;
        $this->memoryUsageBytesAtReset = memory_get_usage(true);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $timings = [];

        foreach ($this->timings as $name => $statistics) {
            $timings[$name] = $this->formatStatistics($statistics);
        }

        ksort($timings);
        $counters = $this->counters;
        ksort($counters);

        $aggregateState = [
            'timings' => $timings,
            'counters' => $counters,
        ];
        $encodedState = json_encode($aggregateState);

        return [
            'enabled' => $this->enabled,
            'elapsedSinceResetSeconds' => round(
                (hrtime(true) - $this->resetAtMonotonicNanoseconds)
                    / 1_000_000_000,
                3,
            ),
            'aggregation' => [
                'timingSeries' => count($this->timings),
                'counterSeries' => count($this->counters),
                'maximumTimingSeries' => self::MAX_TIMING_SERIES,
                'maximumCounterSeries' => self::MAX_COUNTER_SERIES,
                'histogramBucketCount' =>
                    count(self::HISTOGRAM_UPPER_BOUNDS_US) + 1,
                'droppedTimingSamples' => $this->droppedTimingSamples,
                'droppedCounterUpdates' => $this->droppedCounterUpdates,
            ],
            'database' => [
                'maximumPdoCreationsPerSecond' =>
                    $this->maximumPdoCreationsPerSecond,
                'pdoCreationRateResolutionSeconds' => 1,
                'statementExecutionDefinition' =>
                    'PDOStatement::execute calls; transaction controls and connection initialization are separate',
            ],
            'memory' => [
                'processUsageBytesAtReset' =>
                    $this->memoryUsageBytesAtReset,
                'processUsageBytesAtSnapshot' => memory_get_usage(true),
                'processUsageDeltaBytes' =>
                    memory_get_usage(true) - $this->memoryUsageBytesAtReset,
                'serializedAggregateBytes' => is_string($encodedState)
                    ? strlen($encodedState)
                    : null,
            ],
            'timings' => $timings,
            'counters' => $counters,
        ];
    }

    private function setCurrentContext(?string $contextName): void
    {
        if (Coroutine::getCid() >= 0) {
            $context = Coroutine::getContext();

            if ($contextName === null) {
                unset($context[self::COROUTINE_CONTEXT_KEY]);

                return;
            }

            $context[self::COROUTINE_CONTEXT_KEY] = $contextName;

            return;
        }

        $this->fallbackContext = $contextName;
    }

    private function sqlContext(): string
    {
        $context = $this->currentContext();

        if ($context !== null && in_array($context, self::SQL_CONTEXTS, true)) {
            return $context;
        }

        return 'other';
    }

    private function recordMicroseconds(string $name, int $microseconds): void
    {
        if (!isset($this->timings[$name])) {
            if (count($this->timings) >= self::MAX_TIMING_SERIES) {
                $this->droppedTimingSamples++;

                return;
            }

            $this->timings[$name] = [
                'count' => 0,
                'totalUs' => 0,
                'maxUs' => 0,
                'buckets' => array_fill(
                    0,
                    count(self::HISTOGRAM_UPPER_BOUNDS_US) + 1,
                    0,
                ),
            ];
        }

        $statistics = &$this->timings[$name];
        $statistics['count']++;
        $statistics['totalUs'] += $microseconds;
        $statistics['maxUs'] = max($statistics['maxUs'], $microseconds);
        $bucketIndex = count(self::HISTOGRAM_UPPER_BOUNDS_US);

        foreach (self::HISTOGRAM_UPPER_BOUNDS_US as $index => $upperBound) {
            if ($microseconds <= $upperBound) {
                $bucketIndex = $index;

                break;
            }
        }

        $statistics['buckets'][$bucketIndex]++;
    }

    /**
     * @param array{
     *     count: int,
     *     totalUs: int,
     *     maxUs: int,
     *     buckets: int[]
     * } $statistics
     *
     * @return array<string, mixed>
     */
    private function formatStatistics(array $statistics): array
    {
        $histogram = [];

        foreach ($statistics['buckets'] as $index => $count) {
            $upperBound = self::HISTOGRAM_UPPER_BOUNDS_US[$index] ?? null;
            $histogram[] = [
                'leMs' => $upperBound === null
                    ? null
                    : round($upperBound / 1_000, 3),
                'count' => $count,
            ];
        }

        return [
            'count' => $statistics['count'],
            'totalMs' => round($statistics['totalUs'] / 1_000, 3),
            'averageMs' => $statistics['count'] > 0
                ? round(
                    $statistics['totalUs'] / $statistics['count'] / 1_000,
                    3,
                )
                : 0.0,
            'maximumMs' => round($statistics['maxUs'] / 1_000, 3),
            'approximateP50Ms' => $this->approximatePercentile(
                $statistics,
                0.50,
            ),
            'approximateP95Ms' => $this->approximatePercentile(
                $statistics,
                0.95,
            ),
            'approximateP99Ms' => $this->approximatePercentile(
                $statistics,
                0.99,
            ),
            'histogram' => $histogram,
        ];
    }

    /**
     * @param array{
     *     count: int,
     *     totalUs: int,
     *     maxUs: int,
     *     buckets: int[]
     * } $statistics
     */
    private function approximatePercentile(
        array $statistics,
        float $percentile,
    ): float {
        if ($statistics['count'] === 0) {
            return 0.0;
        }

        $target = (int) ceil($statistics['count'] * $percentile);
        $cumulative = 0;

        foreach ($statistics['buckets'] as $index => $count) {
            $cumulative += $count;

            if ($cumulative < $target) {
                continue;
            }

            $upperBound = self::HISTOGRAM_UPPER_BOUNDS_US[$index] ?? null;

            return round(
                ($upperBound ?? $statistics['maxUs']) / 1_000,
                3,
            );
        }

        return round($statistics['maxUs'] / 1_000, 3);
    }
}
