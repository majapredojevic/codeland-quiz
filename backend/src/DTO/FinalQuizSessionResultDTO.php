<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class FinalQuizSessionResultDTO
{
    /**
     * @param FinalSessionLeaderboardEntryDTO[] $leaderboard
     * @param FinalSessionLeaderboardEntryDTO[] $topThree
     */
    public function __construct(
        public QuizSessionItemDTO $session,
        public array $leaderboard,
        public array $topThree,
        public int $participantCount,
        public int $totalAnswerCount,
        public int $totalCorrectAnswerCount,
        public bool $stateChanged,
    ) {
    }
}
