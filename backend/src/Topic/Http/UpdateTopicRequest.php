<?php

declare(strict_types=1);

namespace CodeLandQuiz\Topic\Http;

use CodeLandQuiz\DTO\UpdateTopicDTO;
use CodeLandQuiz\Http\JsonRequest;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class UpdateTopicRequest
{
    public static function from(Request $request): UpdateTopicDTO
    {
        $body = JsonRequest::from($request);
        $hasName = $body->has('name');
        $hasDescription = $body->has('description');

        if (!$hasName && !$hasDescription) {
            throw new InvalidArgumentException('At least one topic field must be provided.');
        }

        return new UpdateTopicDTO(
            hasName: $hasName,
            name: $hasName ? self::nameValue($body->getValue('name')) : null,
            hasDescription: $hasDescription,
            description: $hasDescription
                ? self::descriptionValue($body->getValue('description'))
                : null,
        );
    }

    private static function nameValue(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Topic name must be a string.');
        }

        $name = trim($value);

        if ($name === '') {
            throw new InvalidArgumentException('Topic name cannot be empty.');
        }

        if (strlen($name) > 120) {
            throw new InvalidArgumentException('Topic name cannot exceed 120 characters.');
        }

        return $name;
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
