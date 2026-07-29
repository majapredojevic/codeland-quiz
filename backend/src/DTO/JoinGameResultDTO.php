<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\QuizSessionStatus;

final readonly class JoinGameResultDTO
{
    public function __construct(
        public SessionParticipantItemDTO $participant,
        public int $sessionId,
        public string $quizTitle,
        public int $quizVersion,
        public string $gamePin,
        public QuizSessionStatus $status,
        public IssuedParticipantTokenDTO $participantToken,
    ) {
    }
}
