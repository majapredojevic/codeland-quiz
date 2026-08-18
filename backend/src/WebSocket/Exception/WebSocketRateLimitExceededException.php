<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket\Exception;

use RuntimeException;

final class WebSocketRateLimitExceededException extends RuntimeException
{
}
