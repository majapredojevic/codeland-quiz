<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\ParticipantAnswerOverview;
use DateTimeImmutable;

interface ParticipantAnswerRepository
{
    public function findByParticipantAndQuestion(
        int $participantId,
        int $sessionQuestionId,
    ): ?ParticipantAnswerOverview;

    /**
     * @param int[] $selectedOptionIds
     */
    public function create(
        int $participantId,
        int $sessionQuestionId,
        array $selectedOptionIds,
        bool $isCorrect,
        int $responseTimeMs,
        int $pointsAwarded,
        DateTimeImmutable $answeredAt,
    ): int;
}
