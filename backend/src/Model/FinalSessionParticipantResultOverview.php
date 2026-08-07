<?php

declare(strict_types=1);

namespace CodeLandQuiz\Model;

use DateTimeImmutable;

final readonly class FinalSessionParticipantResultOverview
{
    public function __construct(
        public int $participantId,
        public ParticipantType $participantType,
        public string $nickname,
        public string $avatarKey,
        public int $totalScore,
        public int $answerCount,
        public int $correctAnswerCount,
        public int $totalResponseTimeMs,
        public DateTimeImmutable $joinedAt,
    ) {
    }
}
