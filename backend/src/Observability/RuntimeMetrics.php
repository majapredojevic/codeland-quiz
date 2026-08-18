<?php

declare(strict_types=1);

namespace CodeLandQuiz\Observability;

use CodeLandQuiz\WebSocket\ParticipantConnectionRegistry;
use OpenSwoole\Coroutine;
use OpenSwoole\WebSocket\Server;
use Throwable;

final class RuntimeMetrics
{
    private const SERVER_STAT_KEYS = [
        'up',
        'worker_id',
        'reactor_threads_num',
        'workers_total',
        'workers_idle',
        'task_workers_total',
        'task_workers_idle',
        'tasking_num',
        'user_workers_total',
        'dispatch_total',
        'requests_total',
        'start_seconds',
        'max_conn',
        'connections_accepted',
        'connections_active',
        'connections_closed',
        'reload_count',
        'worker_memory_usage',
        'worker_vm_object_num',
        'worker_vm_resource_num',
        'coroutine_num',
        'event_loop_lag_ms',
        'event_loop_lag_max_ms',
        'event_loop_lag_avg_ms',
    ];

    private const SERVER_SETTING_KEYS = [
        'worker_num',
        'max_request',
        'max_conn',
        'max_coroutine',
        'reactor_num',
        'heartbeat_check_interval',
        'heartbeat_idle_time',
        'package_max_length',
    ];

    private readonly int $startedAtMonotonicNanoseconds;

    private int $workerId = -1;

    private int $httpRequestsObserved = 0;

    private int $heartbeatSweeps = 0;

    private int $staleConnectionsClosed = 0;

    private int $readinessFailures = 0;

    private int $runtimeTicks = 0;

    private bool $runtimeInitialized = false;

    private float $currentEventLoopLagMilliseconds = 0.0;

    private float $maximumEventLoopLagMilliseconds = 0.0;

    public function __construct()
    {
        $this->startedAtMonotonicNanoseconds = hrtime(true);
    }

    public function setWorkerId(int $workerId): void
    {
        $this->workerId = $workerId;
    }

    public function markRuntimeInitialized(bool $initialized): void
    {
        $this->runtimeInitialized = $initialized;
    }

    public function isRuntimeInitialized(): bool
    {
        return $this->runtimeInitialized;
    }

    public function recordHttpRequest(): void
    {
        $this->httpRequestsObserved++;
    }

    public function recordHeartbeatSweep(int $staleConnectionsClosed): void
    {
        $this->heartbeatSweeps++;
        $this->staleConnectionsClosed += max(0, $staleConnectionsClosed);
    }

    public function recordReadinessFailure(): void
    {
        $this->readinessFailures++;
    }

    public function recordEventLoopLag(float $lagMilliseconds): void
    {
        $this->runtimeTicks++;
        $this->currentEventLoopLagMilliseconds = max(0.0, $lagMilliseconds);
        $this->maximumEventLoopLagMilliseconds = max(
            $this->maximumEventLoopLagMilliseconds,
            $this->currentEventLoopLagMilliseconds,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(
        Server $server,
        ParticipantConnectionRegistry $connectionRegistry,
    ): array {
        $serverStats = $server->stats();
        $safeServerStats = [];

        if (is_array($serverStats)) {
            foreach (self::SERVER_STAT_KEYS as $key) {
                $value = $serverStats[$key] ?? null;

                if (is_int($value) || is_float($value)) {
                    $safeServerStats[$key] = $value;

                    continue;
                }

                if (is_string($value) && is_numeric($value)) {
                    $safeServerStats[$key] = str_contains($value, '.')
                        ? (float) $value
                        : (int) $value;
                }
            }
        }

        $coroutineStats = Coroutine::stats();
        $safeServerSettings = [];

        foreach (self::SERVER_SETTING_KEYS as $key) {
            $value = $server->setting[$key] ?? null;

            if (is_int($value)) {
                $safeServerSettings[$key] = $value;
            }
        }

        return [
            'worker' => [
                'id' => $this->workerId,
                'status' => $this->workerStatus($server),
                'initialized' => $this->runtimeInitialized,
                'uptimeSeconds' => round(
                    (hrtime(true) - $this->startedAtMonotonicNanoseconds)
                        / 1_000_000_000,
                    3,
                ),
            ],
            'server' => $safeServerStats,
            'configuration' => $safeServerSettings,
            'coroutines' => [
                'active' => (int) ($coroutineStats['coroutine_num'] ?? 0),
                'peak' => (int) ($coroutineStats['coroutine_peak_num'] ?? 0),
                'lastId' => (int) ($coroutineStats['coroutine_last_cid'] ?? 0),
            ],
            'webSocket' => [
                'pendingConnections' => $connectionRegistry->countPending(),
                'authenticatedConnections' =>
                    $connectionRegistry->countAuthenticated(),
                'heartbeatSweeps' => $this->heartbeatSweeps,
                'staleConnectionsClosed' => $this->staleConnectionsClosed,
            ],
            'http' => [
                'requestsObserved' => $this->httpRequestsObserved,
                'readinessFailures' => $this->readinessFailures,
            ],
            'eventLoop' => [
                'ticks' => $this->runtimeTicks,
                'currentLagMs' => round(
                    $this->currentEventLoopLagMilliseconds,
                    3,
                ),
                'maximumLagMs' => round(
                    $this->maximumEventLoopLagMilliseconds,
                    3,
                ),
            ],
            'memory' => [
                'usageBytes' => memory_get_usage(true),
                'peakUsageBytes' => memory_get_peak_usage(true),
            ],
        ];
    }

    private function workerStatus(Server $server): int|null
    {
        try {
            $status = $server->getWorkerStatus($this->workerId);

            return is_int($status) ? $status : null;
        } catch (Throwable) {
            return null;
        }
    }
}
