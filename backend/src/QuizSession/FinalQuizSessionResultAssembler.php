<?php

declare(strict_types=1);

namespace CodeLandQuiz\QuizSession;

use CodeLandQuiz\DTO\FinalQuizSessionResultDTO;
use CodeLandQuiz\DTO\FinalSessionLeaderboardEntryDTO;
use CodeLandQuiz\DTO\QuizSessionItemDTO;
use CodeLandQuiz\Repository\QuizSessionResultRepository;

final readonly class FinalQuizSessionResultAssembler
{
    public function __construct(
        private QuizSessionResultRepository $results,
    ) {
    }

    public function assemble(
        QuizSessionItemDTO $session,
        bool $stateChanged,
    ): FinalQuizSessionResultDTO {
        $participantRows = $this->results->findFinalParticipantResults(
            $session->id,
        );
        $leaderboard = [];
        $totalAnswerCount = 0;
        $totalCorrectAnswerCount = 0;

        foreach ($participantRows as $participantRow) {
            $leaderboard[] = new FinalSessionLeaderboardEntryDTO(
                rank: count($leaderboard) + 1,
                participantId: $participantRow->participantId,
                participantType: $participantRow->participantType,
                nickname: $participantRow->nickname,
                avatarKey: $participantRow->avatarKey,
                totalScore: $participantRow->totalScore,
                answerCount: $participantRow->answerCount,
                correctAnswerCount: $participantRow->correctAnswerCount,
            );
            $totalAnswerCount += $participantRow->answerCount;
            $totalCorrectAnswerCount += $participantRow->correctAnswerCount;
        }

        return new FinalQuizSessionResultDTO(
            session: $session,
            leaderboard: $leaderboard,
            topThree: array_slice($leaderboard, 0, 3),
            participantCount: count($leaderboard),
            totalAnswerCount: $totalAnswerCount,
            totalCorrectAnswerCount: $totalCorrectAnswerCount,
            stateChanged: $stateChanged,
        );
    }
}
