<?php

declare(strict_types=1);

namespace CodeLandQuiz\Topic\Http;

use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\DTO\ListTopicsDTO;
use CodeLandQuiz\Model\TopicSort;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class ListTopicsRequest
{
    public static function from(
        Request $request,
        AppConfig $config,
    ): ListTopicsDTO {
        $query = $request->get ?? [];
        $pageIndex = self::integerQueryValue(
            $query['pageIndex'] ?? null,
            0,
            'Page index must be a non-negative integer.',
        );
        $pageSize = self::integerQueryValue(
            $query['pageSize'] ?? null,
            $config->getDefaultPageSize(),
            'Page size must be a positive integer.',
        );

        if ($pageIndex < 0) {
            throw new InvalidArgumentException('Page index must be a non-negative integer.');
        }

        if ($pageSize < 1) {
            throw new InvalidArgumentException('Page size must be a positive integer.');
        }

        if ($pageSize > $config->getMaximumPageSize()) {
            throw new InvalidArgumentException('Page size cannot exceed the configured maximum.');
        }

        return new ListTopicsDTO(
            pageIndex: $pageIndex,
            pageSize: $pageSize,
            search: self::searchQueryValue($query['search'] ?? null),
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
            throw new InvalidArgumentException('Topic search must be a string.');
        }

        $search = trim($value);

        if ($search === '') {
            return null;
        }

        if (strlen($search) > 120) {
            throw new InvalidArgumentException('Topic search cannot exceed 120 characters.');
        }

        return $search;
    }

    private static function sortQueryValue(mixed $value): TopicSort
    {
        if ($value === null) {
            return TopicSort::RECENT;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException('Topic sort is invalid.');
        }

        return TopicSort::tryFrom($value)
            ?? throw new InvalidArgumentException('Topic sort is invalid.');
    }
}
