<?php

declare(strict_types=1);

namespace CodeLandQuiz\QuizSession\Http;

use CodeLandQuiz\DTO\QuizSessionHistoryQueryDTO;
use CodeLandQuiz\Model\QuizSessionHistorySort;
use CodeLandQuiz\Model\QuizSessionStatusFilter;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class QuizSessionHistoryQueryRequest
{
    private const DEFAULT_PAGE_INDEX = 0;
    private const DEFAULT_PAGE_SIZE = 10;
    private const MAX_PAGE_SIZE = 20;
    private const MAX_SEARCH_LENGTH = 100;

    public static function from(Request $request): QuizSessionHistoryQueryDTO
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

        return new QuizSessionHistoryQueryDTO(
            pageIndex: $pageIndex,
            pageSize: $pageSize,
            search: self::searchQueryValue($query['search'] ?? null),
            status: self::statusQueryValue($query['status'] ?? null),
            quizId: self::quizIdQueryValue($query['quizId'] ?? null),
            sort: self::sortQueryValue($query['sort'] ?? null),
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

    private static function searchQueryValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(
                'Quiz session search must be a string.',
            );
        }

        $search = trim($value);

        if ($search === '') {
            return null;
        }

        if (mb_strlen($search, 'UTF-8') > self::MAX_SEARCH_LENGTH) {
            throw new InvalidArgumentException(
                'Quiz session search cannot exceed 100 characters.',
            );
        }

        return $search;
    }

    private static function statusQueryValue(
        mixed $value,
    ): QuizSessionStatusFilter {
        if ($value === null) {
            return QuizSessionStatusFilter::ALL;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(
                'Quiz session status is invalid.',
            );
        }

        return QuizSessionStatusFilter::tryFrom($value)
            ?? throw new InvalidArgumentException(
                'Quiz session status is invalid.',
            );
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

    private static function sortQueryValue(
        mixed $value,
    ): QuizSessionHistorySort {
        if ($value === null) {
            return QuizSessionHistorySort::RECENT;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(
                'Quiz session sort is invalid.',
            );
        }

        return QuizSessionHistorySort::tryFrom($value)
            ?? throw new InvalidArgumentException(
                'Quiz session sort is invalid.',
            );
    }
}
