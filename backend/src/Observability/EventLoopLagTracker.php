<?php

declare(strict_types=1);

namespace CodeLandQuiz\Observability;

use Closure;
use InvalidArgumentException;

final class EventLoopLagTracker
{
    private const NANOSECONDS_PER_MILLISECOND = 1_000_000;

    private Closure $monotonicClock;

    private ?int $expectedTickNanoseconds = null;

    public function __construct(
        private readonly int $tickIntervalMilliseconds,
        ?Closure $monotonicClock = null,
    ) {
        if ($this->tickIntervalMilliseconds < 1) {
            throw new InvalidArgumentException(
                'Runtime tick interval must be greater than zero.',
            );
        }

        $this->monotonicClock = $monotonicClock
            ?? static fn (): int => hrtime(true);
    }

    public function start(): void
    {
        $this->expectedTickNanoseconds = ($this->monotonicClock)()
            + $this->intervalNanoseconds();
    }

    public function sample(): float
    {
        $now = ($this->monotonicClock)();

        if ($this->expectedTickNanoseconds === null) {
            $this->expectedTickNanoseconds = $now
                + $this->intervalNanoseconds();

            return 0.0;
        }

        $lagNanoseconds = max(0, $now - $this->expectedTickNanoseconds);
        $this->expectedTickNanoseconds = $now
            + $this->intervalNanoseconds();

        return $lagNanoseconds / self::NANOSECONDS_PER_MILLISECOND;
    }

    private function intervalNanoseconds(): int
    {
        return $this->tickIntervalMilliseconds
            * self::NANOSECONDS_PER_MILLISECOND;
    }
}
