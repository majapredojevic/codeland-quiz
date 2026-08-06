<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use CodeLandQuiz\DTO\SubmitAnswerDTO;
use InvalidArgumentException;

final class AnswerSubmitMessage
{
    private const INVALID_MESSAGE =
        'A valid answer submission message is required.';

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): SubmitAnswerDTO
    {
        $selectedOptionIds = $payload['selectedOptionIds'] ?? null;

        if (
            !is_array($selectedOptionIds)
            || !array_is_list($selectedOptionIds)
            || count($selectedOptionIds) < 1
            || count($selectedOptionIds) > 4
        ) {
            throw new InvalidArgumentException(self::INVALID_MESSAGE);
        }

        $uniqueIds = [];

        foreach ($selectedOptionIds as $selectedOptionId) {
            if (!is_int($selectedOptionId) || $selectedOptionId < 1) {
                throw new InvalidArgumentException(self::INVALID_MESSAGE);
            }

            if (isset($uniqueIds[$selectedOptionId])) {
                throw new InvalidArgumentException(self::INVALID_MESSAGE);
            }

            $uniqueIds[$selectedOptionId] = true;
        }

        return new SubmitAnswerDTO(
            selectedOptionIds: $selectedOptionIds,
        );
    }
}
