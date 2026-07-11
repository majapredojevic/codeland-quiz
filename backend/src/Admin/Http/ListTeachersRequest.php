<?php

declare(strict_types=1);

namespace CodeLandQuiz\Admin\Http;

use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\DTO\ListTeachersDTO;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class ListTeachersRequest
{
    public static function from(
        Request $request,
        AppConfig $config,
    ): ListTeachersDTO {
        $pageIndex = self::integerQueryValue(
            $request->get['pageIndex'] ?? null,
            0,
            'Page index must be a non-negative integer.',
        );
        $pageSize = self::integerQueryValue(
            $request->get['pageSize'] ?? null,
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

        return new ListTeachersDTO(
            pageIndex: $pageIndex,
            pageSize: $pageSize,
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
}
