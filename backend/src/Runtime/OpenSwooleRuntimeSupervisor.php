<?php

declare(strict_types=1);

namespace CodeLandQuiz\Runtime;

use Closure;
use CodeLandQuiz\Observability\EventLoopLagTracker;
use CodeLandQuiz\Observability\RuntimeLogger;
use CodeLandQuiz\Observability\RuntimeMetrics;
use CodeLandQuiz\WebSocket\WebSocketGatewayRouter;
use OpenSwoole\Timer;
use OpenSwoole\WebSocket\Server;
use RuntimeException;
use Throwable;

final class OpenSwooleRuntimeSupervisor
{
    private const RUNTIME_TICK_INTERVAL_MILLISECONDS = 1_000;
    private const NANOSECONDS_PER_SECOND = 1_000_000_000;

    private Closure $monotonicClock;

    private EventLoopLagTracker $eventLoopLagTracker;

    private ?int $timerId = null;

    private ?int $nextHeartbeatNanoseconds = null;

    private bool $tickRunning = false;

    public function __construct(
        private readonly Server $server,
        private readonly WebSocketGatewayRouter $webSocketGateway,
        private readonly RuntimeMetrics $metrics,
        private readonly RuntimeLogger $logger,
        private readonly int $heartbeatIntervalSeconds,
        private readonly int $staleTimeoutSeconds,
        ?Closure $monotonicClock = null,
    ) {
        $this->monotonicClock = $monotonicClock
            ?? static fn (): int => hrtime(true);
        $this->eventLoopLagTracker = new EventLoopLagTracker(
            self::RUNTIME_TICK_INTERVAL_MILLISECONDS,
            $this->monotonicClock,
        );
    }

    public function start(int $workerId): void
    {
        if ($this->timerId !== null) {
            return;
        }

        $this->metrics->setWorkerId($workerId);
        $this->eventLoopLagTracker->start();
        $this->nextHeartbeatNanoseconds = ($this->monotonicClock)()
            + $this->heartbeatIntervalSeconds * self::NANOSECONDS_PER_SECOND;
        $timerId = Timer::tick(
            self::RUNTIME_TICK_INTERVAL_MILLISECONDS,
            function (): void {
                $this->tick();
            },
        );

        if (!is_int($timerId)) {
            throw new RuntimeException('OpenSwoole runtime timer could not start.');
        }

        $this->timerId = $timerId;
        $this->logger->info('runtime.timer_started', [
            'workerId' => $workerId,
            'count' => 1,
        ]);
    }

    public function stop(int $workerId): void
    {
        if ($this->timerId === null) {
            return;
        }

        Timer::clear($this->timerId);
        $this->timerId = null;
        $this->nextHeartbeatNanoseconds = null;
        $this->tickRunning = false;
        $this->logger->info('runtime.timer_stopped', [
            'workerId' => $workerId,
            'count' => 0,
        ]);
    }

    public function isRunning(): bool
    {
        return $this->timerId !== null;
    }

    private function tick(): void
    {
        if ($this->tickRunning) {
            return;
        }

        $this->tickRunning = true;

        try {
            $this->metrics->recordEventLoopLag(
                $this->eventLoopLagTracker->sample(),
            );
            $now = ($this->monotonicClock)();

            if (
                $this->nextHeartbeatNanoseconds === null
                || $now < $this->nextHeartbeatNanoseconds
            ) {
                return;
            }

            $this->nextHeartbeatNanoseconds = $now
                + $this->heartbeatIntervalSeconds
                    * self::NANOSECONDS_PER_SECOND;
            $closed = $this->webSocketGateway->heartbeatSweep(
                server: $this->server,
                monotonicNanoseconds: $now,
                staleTimeoutSeconds: $this->staleTimeoutSeconds,
            );
            $this->metrics->recordHeartbeatSweep($closed);
        } catch (Throwable $throwable) {
            $this->logger->error('runtime.tick_failed', [
                'exception' => $throwable::class,
            ]);
        } finally {
            $this->tickRunning = false;
        }
    }
}
