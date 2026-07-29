<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\SessionQuestionOverview;

interface SessionQuestionRepository
{
    public function findBySessionAndOrder(
        int $sessionId,
        int $questionOrder,
    ): ?SessionQuestionOverview;
}
