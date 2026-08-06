<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use CodeLandQuiz\Model\ParticipantType;

final readonly class SessionLeaderboardEntryDTO
{
    public function __construct(
        public int $rank,
        public int $participantId,
        public ParticipantType $participantType,
        public string $nickname,
        public string $avatarKey,
        public int $totalScore,
        public int $pointsAwardedThisQuestion,
    ) {
    }
}
