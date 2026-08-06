<?php

declare(strict_types=1);

namespace CodeLandQuiz\QuizSession;

use CodeLandQuiz\DTO\ClosedSessionQuestionStateDTO;
use CodeLandQuiz\DTO\SessionLeaderboardEntryDTO;
use CodeLandQuiz\DTO\SessionQuestionParticipantResultDTO;
use CodeLandQuiz\DTO\SessionQuestionStatsDTO;
use CodeLandQuiz\Model\SessionQuestionOptionOverview;
use CodeLandQuiz\Model\SessionQuestionOverview;
use CodeLandQuiz\Model\SessionQuestionParticipantResultOverview;
use CodeLandQuiz\Repository\QuizSessionResultRepository;
use DateTimeImmutable;

final readonly class ClosedQuestionResultAssembler
{
    public function __construct(
        private QuizSessionResultRepository $results,
    ) {
    }

    public function assemble(
        SessionQuestionOverview $question,
        DateTimeImmutable $closedAt,
    ): ClosedSessionQuestionStateDTO {
        $participantRows = $this->results->findQuestionParticipantResults(
            $question->sessionId,
            $question->id,
        );

        return new ClosedSessionQuestionStateDTO(
            question: (new PublicSessionQuestionMapper())->map($question),
            closedAt: $closedAt,
            correctOptionIds: $this->correctOptionIds($question),
            stats: $this->buildStats($participantRows),
            participantResults: array_map(
                $this->mapParticipantResult(...),
                $participantRows,
            ),
            leaderboard: $this->buildLeaderboard($participantRows),
        );
    }

    /**
     * @return int[]
     */
    private function correctOptionIds(
        SessionQuestionOverview $question,
    ): array {
        $correctOptions = array_values(array_filter(
            $question->options,
            static fn(SessionQuestionOptionOverview $option): bool =>
                $option->isCorrect,
        ));

        usort(
            $correctOptions,
            static fn(
                SessionQuestionOptionOverview $left,
                SessionQuestionOptionOverview $right,
            ): int => $left->optionOrder <=> $right->optionOrder,
        );

        return array_map(
            static fn(SessionQuestionOptionOverview $option): int =>
                $option->id,
            $correctOptions,
        );
    }

    private function mapParticipantResult(
        SessionQuestionParticipantResultOverview $row,
    ): SessionQuestionParticipantResultDTO {
        $answered = $row->selectedOptionIds !== null;

        return new SessionQuestionParticipantResultDTO(
            participantId: $row->participantId,
            participantType: $row->participantType,
            nickname: $row->nickname,
            avatarKey: $row->avatarKey,
            answered: $answered,
            selectedOptionIds: $row->selectedOptionIds ?? [],
            isCorrect: $answered ? $row->isCorrect : null,
            responseTimeMs: $answered ? $row->responseTimeMs : null,
            pointsAwarded: $answered ? $row->pointsAwarded : 0,
            totalScore: $row->totalScore,
            answeredAt: $answered ? $row->answeredAt : null,
        );
    }

    /**
     * @param SessionQuestionParticipantResultOverview[] $rows
     */
    private function buildStats(array $rows): SessionQuestionStatsDTO
    {
        $answerCount = 0;
        $correctAnswerCount = 0;
        $incorrectAnswerCount = 0;

        foreach ($rows as $row) {
            if ($row->selectedOptionIds === null) {
                continue;
            }

            $answerCount++;

            if ($row->isCorrect === true) {
                $correctAnswerCount++;
            } elseif ($row->isCorrect === false) {
                $incorrectAnswerCount++;
            }
        }

        $participantCount = count($rows);

        return new SessionQuestionStatsDTO(
            participantCount: $participantCount,
            answerCount: $answerCount,
            correctAnswerCount: $correctAnswerCount,
            incorrectAnswerCount: $incorrectAnswerCount,
            unansweredCount: $participantCount - $answerCount,
        );
    }

    /**
     * @param SessionQuestionParticipantResultOverview[] $rows
     *
     * @return SessionLeaderboardEntryDTO[]
     */
    private function buildLeaderboard(array $rows): array
    {
        $sortedRows = $rows;
        usort($sortedRows, self::compareLeaderboardRows(...));

        return array_map(
            static fn(
                SessionQuestionParticipantResultOverview $row,
                int $index,
            ): SessionLeaderboardEntryDTO =>
                new SessionLeaderboardEntryDTO(
                    rank: $index + 1,
                    participantId: $row->participantId,
                    participantType: $row->participantType,
                    nickname: $row->nickname,
                    avatarKey: $row->avatarKey,
                    totalScore: $row->totalScore,
                    pointsAwardedThisQuestion: $row->pointsAwarded,
                ),
            $sortedRows,
            array_keys($sortedRows),
        );
    }

    private static function compareLeaderboardRows(
        SessionQuestionParticipantResultOverview $left,
        SessionQuestionParticipantResultOverview $right,
    ): int {
        $comparison = $right->totalScore <=> $left->totalScore;

        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = $right->pointsAwarded <=> $left->pointsAwarded;

        if ($comparison !== 0) {
            return $comparison;
        }

        $leftAnswered = $left->selectedOptionIds !== null;
        $rightAnswered = $right->selectedOptionIds !== null;

        if ($leftAnswered !== $rightAnswered) {
            return $leftAnswered ? -1 : 1;
        }

        if ($left->responseTimeMs !== $right->responseTimeMs) {
            if ($left->responseTimeMs === null) {
                return 1;
            }

            if ($right->responseTimeMs === null) {
                return -1;
            }

            return $left->responseTimeMs <=> $right->responseTimeMs;
        }

        $comparison = $left->joinedAt <=> $right->joinedAt;

        if ($comparison !== 0) {
            return $comparison;
        }

        return $left->participantId <=> $right->participantId;
    }
}
