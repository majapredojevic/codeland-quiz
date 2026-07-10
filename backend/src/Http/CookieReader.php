<?php

declare(strict_types=1);

namespace CodeLandQuiz\Http;

use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class CookieReader
{
    public function getCookie(Request $request, string $name): string
    {
        $value = $request->cookie[$name] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(
                sprintf('Cookie "%s" is missing.', $name),
            );
        }

        return $value;
    }
}