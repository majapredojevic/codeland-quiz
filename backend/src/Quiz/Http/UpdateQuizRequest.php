<?php

declare(strict_types=1);

namespace CodeLandQuiz\Quiz\Http;

use CodeLandQuiz\DTO\UpdateQuizDTO;
use CodeLandQuiz\Http\JsonRequest;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class UpdateQuizRequest
{
    private const MAX_TITLE_LENGTH = 180;
    private const MAX_DESCRIPTION_LENGTH = 5000;

    public static function from(Request $request): UpdateQuizDTO
    {
        $body = JsonRequest::from($request);
        $hasTitle = $body->has('title');
        $hasVersion = $body->has('version');
        $hasDescription = $body->has('description');
        $hasTopicId = $body->has('topicId');

        if (
            !$hasTitle
            && !$hasVersion
            && !$hasDescription
            && !$hasTopicId
        ) {
            throw new InvalidArgumentException('At least one quiz field must be provided.');
        }

        return new UpdateQuizDTO(
            hasTitle: $hasTitle,
            title: $hasTitle ? self::titleValue($body->getValue('title')) : null,
            hasVersion: $hasVersion,
            version: $hasVersion
                ? self::positiveIntegerValue(
                    $body->getValue('version'),
                    'Quiz version must be a positive integer.',
                )
                : null,
            hasDescription: $hasDescription,
            description: $hasDescription
                ? self::descriptionValue($body->getValue('description'))
                : null,
            hasTopicId: $hasTopicId,
            topicId: $hasTopicId
                ? self::topicIdValue($body->getValue('topicId'))
                : null,
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
