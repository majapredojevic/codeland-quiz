<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use InvalidArgumentException;

final readonly class WebSocketFramePolicy
{
    public function __construct(
        private int $maximumBytes,
    ) {
        if ($this->maximumBytes < 1) {
            throw new InvalidArgumentException(
                'WebSocket maximum frame size must be greater than zero.',
            );
        }
    }

    public function allows(string $frameData): bool
    {
        return strlen($frameData) <= $this->maximumBytes;
    }
}
