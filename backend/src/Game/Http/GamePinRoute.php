<?php

declare(strict_types=1);

namespace CodeLandQuiz\Game\Http;

use CodeLandQuiz\Http\RequestContext;
use InvalidArgumentException;

final class GamePinRoute
{
    private const GAME_PIN_PATTERN = '/^[0-9]{6}$/';

    public static function fromContext(
        RequestContext $context,
        string $parameterName = 'gamePin',
    ): string {
        $gamePin = $context->getRouteParameter($parameterName);

        if (preg_match(self::GAME_PIN_PATTERN, $gamePin) !== 1) {
            throw new InvalidArgumentException(
                'Game PIN must contain exactly six digits.',
            );
        }

        return $gamePin;
    }
}
