<?php

declare(strict_types=1);

namespace CodeLandQuiz\DTO;

final readonly class QuizSessionReportDTO
{
    /**
     * @param FinalSessionLeaderboardEntryDTO[] $leaderboard
     * @param SessionReportQuestionDTO[] $questions
     * @param SessionReportParticipantDTO[] $participants
     */
    public function __construct(
        public QuizSessionItemDTO $session,
        public QuizSessionReportSummaryDTO $summary,
        public array $leaderboard,
        public array $questions,
        public array $participants,
    ) {
    }
}
