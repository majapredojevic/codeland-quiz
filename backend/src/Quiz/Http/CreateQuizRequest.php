<?php

declare(strict_types=1);

namespace CodeLandQuiz\Quiz\Http;

use CodeLandQuiz\DTO\CreateQuizDTO;
use CodeLandQuiz\Http\JsonRequest;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class CreateQuizRequest
{
    private const MAX_TITLE_LENGTH = 180;
    private const MAX_DESCRIPTION_LENGTH = 5000;

    public static function from(Request $request): CreateQuizDTO
    {
        $body = JsonRequest::from($request);

        if (!$body->has('title')) {
            throw new InvalidArgumentException('Quiz title is required.');
        }

        if (!$body->has('version')) {
            throw new InvalidArgumentException('Quiz version is required.');
        }

        return new CreateQuizDTO(
            title: self::titleValue($body->getValue('title')),
            version: self::positiveIntegerValue(
                $body->getValue('version'),
                'Quiz version must be a positive integer.',
            ),
            description: self::descriptionValue($body->getValue('description')),
            topicId: self::topicIdValue($body->getValue('topicId')),
        );
    }

    private static function titleValue(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Quiz title must be a string.');
        }

        $title = trim($value);

        if ($title === '') {
            throw new InvalidArgumentException('Quiz title cannot be empty.');
        }

        if (strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new InvalidArgumentException('Quiz title cannot exceed 180 characters.');
        }

        return $title;
    }

    private static function descriptionValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException('Quiz description must be a string or null.');
        }

        $description = trim($value);

        if ($description === '') {
            return null;
        }

        if (strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new InvalidArgumentException('Quiz description cannot exceed 5000 characters.');
        }

        return $description;
    }

    private static function topicIdValue(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return self::positiveIntegerValue(
            $value,
            'Topic ID must be a positive integer or null.',
        );
    }

    private static function positiveIntegerValue(
        mixed $value,
        string $message,
    ): int {
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }
}
