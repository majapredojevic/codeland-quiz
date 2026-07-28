<?php

declare(strict_types=1);

namespace CodeLandQuiz\Quiz\Http;

use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\DTO\ListQuizzesDTO;
use CodeLandQuiz\Model\QuizSort;
use CodeLandQuiz\Model\QuizStatusFilter;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class ListQuizzesRequest
{
    public static function from(
        Request $request,
        AppConfig $config,
    ): ListQuizzesDTO {
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

        return new ListQuizzesDTO(
            pageIndex: $pageIndex,
            pageSize: $pageSize,
            search: self::searchQueryValue($query['search'] ?? null),
            topicId: self::topicIdQueryValue($query['topicId'] ?? null),
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
            throw new InvalidArgumentException('Quiz search must be a string.');
        }

        $search = trim($value);

        if ($search === '') {
            return null;
        }

        if (strlen($search) > 180) {
            throw new InvalidArgumentException('Quiz search cannot exceed 180 characters.');
        }

        return $search;
    }

    private static function topicIdQueryValue(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            throw new InvalidArgumentException('Topic ID must be a positive integer.');
        }

        $topicId = filter_var($value, FILTER_VALIDATE_INT);

        if ($topicId === false || $topicId < 1) {
            throw new InvalidArgumentException('Topic ID must be a positive integer.');
        }

        return $topicId;
    }

    private static function statusQueryValue(mixed $value): QuizStatusFilter
    {
        if ($value === null) {
            return QuizStatusFilter::ALL;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException('Quiz status is invalid.');
        }

        return QuizStatusFilter::tryFrom($value)
            ?? throw new InvalidArgumentException('Quiz status is invalid.');
    }

    private static function sortQueryValue(mixed $value): QuizSort
    {
        if ($value === null) {
            return QuizSort::RECENT;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException('Quiz sort is invalid.');
        }

        return QuizSort::tryFrom($value)
            ?? throw new InvalidArgumentException('Quiz sort is invalid.');
    }
}
