<?php

declare(strict_types=1);

namespace CodeLandQuiz\Http;

use InvalidArgumentException;
use JsonException;
use OpenSwoole\Http\Request;

final class JsonRequest
{
    public static function from(Request $request): RequestBody
    {
        $content = $request->rawContent();

        if ($content === '') {
            return new RequestBody([]);
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid JSON request body.', 0, $exception);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('JSON request body must be an object.');
        }

        return new RequestBody($decoded);
    }
}
