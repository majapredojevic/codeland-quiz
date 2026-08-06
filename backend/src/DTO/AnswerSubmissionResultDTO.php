<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use DateTimeImmutable;

final readonly class AnswerSubmissionResultDTO
{
    public function __construct(
        public int $questionOrder,
        public int $responseTimeMs,
        public DateTimeImmutable $answeredAt,
    ) {
    }
}
