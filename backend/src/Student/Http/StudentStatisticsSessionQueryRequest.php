<?php

declare(strict_types=1);

namespace CodeLandQuiz\Student\Http;

use CodeLandQuiz\DTO\StudentStatisticsSessionQueryDTO;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class StudentStatisticsSessionQueryRequest
{
    private const DEFAULT_PAGE_INDEX = 0;
    private const DEFAULT_PAGE_SIZE = 10;
    private const MAX_PAGE_SIZE = 20;

    public static function from(Request $request): StudentStatisticsSessionQueryDTO
    {
        $query = $request->get ?? [];
        $pageIndex = self::integerQueryValue(
            $query['pageIndex'] ?? null,
            self::DEFAULT_PAGE_INDEX,
            'Page index must be a non-negative integer.',
        );
        $pageSize = self::integerQueryValue(
            $query['pageSize'] ?? null,
            self::DEFAULT_PAGE_SIZE,
            'Page size must be a positive integer.',
        );

        if ($pageIndex < 0) {
            throw new InvalidArgumentException(
                'Page index must be a non-negative integer.',
            );
        }

        if ($pageSize < 1) {
            throw new InvalidArgumentException(
                'Page size must be a positive integer.',
            );
        }

        if ($pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException(
                'Page size cannot exceed the configured maximum.',
            );
        }

        return new StudentStatisticsSessionQueryDTO(
            pageIndex: $pageIndex,
            pageSize: $pageSize,
            quizId: self::quizIdQueryValue($query['quizId'] ?? null),
        );
    }

    private static function integerQueryValue(
        mixed $value,
        int $default,
        string $message,
    ): int {
        if ($value === null) {
            return $default;
        }

        if (is_array($value)) {
            throw new InvalidArgumentException($message);
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        if ($integer === false) {
            throw new InvalidArgumentException($message);
        }

        return $integer;
    }

    private static function quizIdQueryValue(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            throw new InvalidArgumentException(
                'Quiz ID must be a positive integer.',
            );
        }

        $quizId = filter_var($value, FILTER_VALIDATE_INT);

        if ($quizId === false || $quizId < 1) {
            throw new InvalidArgumentException(
                'Quiz ID must be a positive integer.',
            );
        }

        return $quizId;
    }
}
