<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\SessionQuestionOverview;
use CodeLandQuiz\Model\SessionReportAnswerOverview;
use CodeLandQuiz\Model\SessionReportParticipantOverview;

interface QuizSessionReportRepository
{
    /**
     * @return SessionQuestionOverview[]
     */
    public function findQuestions(int $sessionId): array;

    /**
     * @return SessionReportParticipantOverview[]
     */
    public function findParticipants(int $sessionId): array;

    /**
     * @return SessionReportAnswerOverview[]
     */
    public function findAnswers(int $sessionId): array;
}
