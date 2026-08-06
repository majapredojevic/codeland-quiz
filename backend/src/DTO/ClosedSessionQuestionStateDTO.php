<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

use DateTimeImmutable;

final readonly class ClosedSessionQuestionStateDTO
{
    /**
     * @param int[] $correctOptionIds
     * @param SessionQuestionParticipantResultDTO[] $participantResults
     * @param SessionLeaderboardEntryDTO[] $leaderboard
     */
    public function __construct(
        public PublicSessionQuestionDTO $question,
        public DateTimeImmutable $closedAt,
        public array $correctOptionIds,
        public SessionQuestionStatsDTO $stats,
        public array $participantResults,
        public array $leaderboard,
    ) {
    }
}
