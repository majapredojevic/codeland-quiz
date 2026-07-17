<?php

declare(strict_types=1);

namespace CodeLandQuiz\Topic\Http;

use CodeLandQuiz\DTO\CreateTopicDTO;
use CodeLandQuiz\Http\JsonRequest;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class CreateTopicRequest
{
    public static function from(Request $request): CreateTopicDTO
    {
        $body = JsonRequest::from($request);

        if (!$body->has('name')) {
            throw new InvalidArgumentException('Topic name is required.');
        }

        $name = $body->getValue('name');

        if (!is_string($name)) {
            throw new InvalidArgumentException('Topic name must be a string.');
        }

        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Topic name cannot be empty.');
        }

        if (strlen($name) > 120) {
            throw new InvalidArgumentException('Topic name cannot exceed 120 characters.');
        }

        return new CreateTopicDTO(
            name: $name,
            description: self::descriptionValue($body->getValue('description')),
        );
    }

    private static function descriptionValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException('Topic description must be a string or null.');
        }

        $description = trim($value);

        if ($description === '') {
            return null;
        }

        if (strlen($description) > 255) {
            throw new InvalidArgumentException('Topic description cannot exceed 255 characters.');
        }

        return $description;
    }
}
