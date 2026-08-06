<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\SessionQuestionParticipantResultOverview;

interface QuizSessionResultRepository
{
    public function recalculateParticipantTotalScores(
        int $sessionId,
    ): void;

    /**
     * @return SessionQuestionParticipantResultOverview[]
     */
    public function findQuestionParticipantResults(
        int $sessionId,
        int $sessionQuestionId,
    ): array;
}
