<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class SubmitAnswerDTO
{
    /**
     * @param int[] $selectedOptionIds
     */
    public function __construct(
        public array $selectedOptionIds,
    ) {
    }
}
