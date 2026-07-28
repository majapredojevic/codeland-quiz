<?php

declare(strict_types=1);

namespace CodeLandQuiz\Question\Http;

use CodeLandQuiz\DTO\ReorderQuestionsDTO;
use CodeLandQuiz\Http\JsonRequest;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class ReorderQuestionsRequest
{
    public static function from(Request $request): ReorderQuestionsDTO
    {
        $body = JsonRequest::from($request);

        if (!$body->has('questionIds')) {
            throw new InvalidArgumentException('Question IDs are required.');
        }

        $questionIds = $body->getValue('questionIds');

        if (!is_array($questionIds) || !array_is_list($questionIds)) {
            throw new InvalidArgumentException('Question IDs must be an array.');
        }

        if ($questionIds === []) {
            throw new InvalidArgumentException('Question IDs cannot be empty.');
        }

        foreach ($questionIds as $questionId) {
            if (!is_int($questionId) || $questionId < 1) {
                throw new InvalidArgumentException(
                    'Every question ID must be a positive integer.',
                );
            }
        }

        if (count($questionIds) !== count(array_unique($questionIds))) {
            throw new InvalidArgumentException('Question IDs must be unique.');
        }

        return new ReorderQuestionsDTO($questionIds);
    }
}
