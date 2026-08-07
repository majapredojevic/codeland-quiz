<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\QuizSessionStatus;

final readonly class SessionParticipantListDTO
{
    /**
     * @param SessionParticipantAdminDTO[] $participants
     */
    public function __construct(
        public int $sessionId,
        public QuizSessionStatus $sessionStatus,
        public ?int $currentQuestionOrder,
        public array $participants,
        public int $participantCount,
        public int $connectedParticipantCount,
        public int $answeredCurrentQuestionCount,
    ) {
    }
}
