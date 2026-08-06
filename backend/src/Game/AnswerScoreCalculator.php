<?php

declare(strict_types=1);

namespace CodeLandQuiz\Game;

use InvalidArgumentException;

final class AnswerScoreCalculator
{
    public function calculate(
        bool $isCorrect,
        int $maxPoints,
        int $responseTimeMs,
        int $timeLimitSeconds,
    ): int {
        if ($maxPoints < 1) {
            throw new InvalidArgumentException(
                'Maximum points must be greater than zero.',
            );
        }

        if ($responseTimeMs < 0) {
            throw new InvalidArgumentException(
                'Response time must not be negative.',
            );
        }

        if ($timeLimitSeconds < 1) {
            throw new InvalidArgumentException(
                'Time limit must be greater than zero.',
            );
        }

        if (!$isCorrect) {
            return 0;
        }

        $timeLimitMs = $timeLimitSeconds * 1000;
        $boundedResponseTimeMs = max(
            0,
            min($responseTimeMs, $timeLimitMs),
        );
        $remainingRatio = ($timeLimitMs - $boundedResponseTimeMs)
            / $timeLimitMs;
        $multiplier = 0.5 + (0.5 * $remainingRatio);
        $points = (int) round(
            $maxPoints * $multiplier,
            0,
            PHP_ROUND_HALF_UP,
        );

        return max(0, min($points, $maxPoints));
    }
}
