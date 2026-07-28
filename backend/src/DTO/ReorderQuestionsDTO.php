<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class ReorderQuestionsDTO
{
    /**
     * @param int[] $questionIds
     */
    public function __construct(
        public array $questionIds,
    ) {
    }
}
