<?php

declare(strict_types=1);

namespace CodeLandQuiz\Student\Http;

use CodeLandQuiz\DTO\StudentListQueryDTO;
use CodeLandQuiz\Model\StudentSort;
use CodeLandQuiz\Model\StudentStatusFilter;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class StudentListQueryRequest
{
    private const DEFAULT_PAGE_INDEX = 0;
    private const DEFAULT_PAGE_SIZE = 10;
    private const MAX_PAGE_SIZE = 20;
    private const MAX_SEARCH_LENGTH = 100;

    public static function from(Request $request): StudentListQueryDTO
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
            throw new InvalidArgumentException('Page index must be a non-negative integer.');
        }

        if ($pageSize < 1) {
            throw new InvalidArgumentException('Page size must be a positive integer.');
        }

        if ($pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException('Page size cannot exceed the configured maximum.');
        }

        return new StudentListQueryDTO(
            pageIndex: $pageIndex,
            pageSize: $pageSize,
            search: self::searchQueryValue($query['search'] ?? null),
            status: self::statusQueryValue($query['status'] ?? null),
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
            throw new InvalidArgumentException('Student search must be a string.');
        }

        $search = trim($value);

        if ($search === '') {
            return null;
        }

        if (mb_strlen($search, 'UTF-8') > self::MAX_SEARCH_LENGTH) {
            throw new InvalidArgumentException('Student search cannot exceed 100 characters.');
        }

        return $search;
    }

    private static function statusQueryValue(mixed $value): StudentStatusFilter
    {
        if ($value === null) {
            return StudentStatusFilter::ALL;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException('Student status is invalid.');
        }

        return StudentStatusFilter::tryFrom($value)
            ?? throw new InvalidArgumentException('Student status is invalid.');
    }

    private static function sortQueryValue(mixed $value): StudentSort
    {
        if ($value === null) {
            return StudentSort::RECENT;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException('Student sort is invalid.');
        }

        return StudentSort::tryFrom($value)
            ?? throw new InvalidArgumentException('Student sort is invalid.');
    }
}
