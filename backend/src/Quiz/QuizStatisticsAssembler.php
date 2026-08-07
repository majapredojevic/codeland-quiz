<?php

declare(strict_types=1);

namespace CodeLandQuiz\Quiz;

use CodeLandQuiz\DTO\QuizQuestionStatisticsDTO;
use CodeLandQuiz\DTO\QuizStatisticsDTO;
use CodeLandQuiz\DTO\QuizStatisticsSummaryDTO;
use CodeLandQuiz\Repository\QuizStatisticsRepository;

final readonly class QuizStatisticsAssembler
{
    public function __construct(
        private QuizStatisticsRepository $statistics,
    ) {
    }

    public function assemble(
        int $quizId,
        string $quizTitle,
        int $quizVersion,
    ): QuizStatisticsDTO {
        $summary = $this->statistics->findSummary($quizId);
        $questionRows = $this->statistics->findQuestionSessionStatistics(
            $quizId,
        );
        $groups = [];

        foreach ($questionRows as $row) {
            $groupKey = $row->sourceQuestionId === null
                ? 'snapshot:' . $row->sessionQuestionId
                : 'source:' . $row->sourceQuestionId;

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'key' => $groupKey,
                    'sourceQuestionId' => $row->sourceQuestionId,
                    'questionText' => $row->questionText,
                    'questionType' => $row->questionType,
                    'latestQuestionOrder' => $row->questionOrder,
                    'isCurrentlyDeleted' =>
                        $row->sourceQuestionCurrentlyDeleted,
                    'sessionIds' => [],
                    'participantOpportunityCount' => 0,
                    'answerCount' => 0,
                    'correctAnswerCount' => 0,
                    'totalResponseTimeMs' => 0,
                    'totalPointsAwarded' => 0,
                ];
            }

            $group = &$groups[$groupKey];
            $group['questionText'] = $row->questionText;
            $group['questionType'] = $row->questionType;
            $group['latestQuestionOrder'] = $row->questionOrder;
            $group['isCurrentlyDeleted'] =
                $row->sourceQuestionCurrentlyDeleted;
            $group['sessionIds'][$row->sessionId] = true;
            $group['participantOpportunityCount'] +=
                $row->participantOpportunityCount;
            $group['answerCount'] += $row->answerCount;
            $group['correctAnswerCount'] += $row->correctAnswerCount;
            $group['totalResponseTimeMs'] += $row->totalResponseTimeMs;
            $group['totalPointsAwarded'] += $row->totalPointsAwarded;
            unset($group);
        }

        $questionGroups = array_values($groups);

        usort(
            $questionGroups,
            static function (array $left, array $right): int {
                $orderComparison = $left['latestQuestionOrder']
                    <=> $right['latestQuestionOrder'];

                if ($orderComparison !== 0) {
                    return $orderComparison;
                }

                $leftSourceId = $left['sourceQuestionId'];
                $rightSourceId = $right['sourceQuestionId'];

                if ($leftSourceId === null && $rightSourceId !== null) {
                    return 1;
                }

                if ($leftSourceId !== null && $rightSourceId === null) {
                    return -1;
                }

                if ($leftSourceId !== null && $rightSourceId !== null) {
                    $sourceComparison = $leftSourceId <=> $rightSourceId;

                    if ($sourceComparison !== 0) {
                        return $sourceComparison;
                    }
                }

                return strcmp($left['key'], $right['key']);
            },
        );

        $questions = [];

        foreach ($questionGroups as $group) {
            $answerCount = $group['answerCount'];
            $participantOpportunityCount =
                $group['participantOpportunityCount'];

            $questions[] = new QuizQuestionStatisticsDTO(
                sourceQuestionId: $group['sourceQuestionId'],
                questionText: $group['questionText'],
                questionType: $group['questionType'],
                latestQuestionOrder: $group['latestQuestionOrder'],
                isCurrentlyDeleted: $group['isCurrentlyDeleted'],
                sessionCount: count($group['sessionIds']),
                participantOpportunityCount: $participantOpportunityCount,
                answerCount: $answerCount,
                correctAnswerCount: $group['correctAnswerCount'],
                incorrectAnswerCount: max(
                    0,
                    $answerCount - $group['correctAnswerCount'],
                ),
                unansweredCount: max(
                    0,
                    $participantOpportunityCount - $answerCount,
                ),
                accuracyPercentage: $this->percentage(
                    $group['correctAnswerCount'],
                    $answerCount,
                ),
                answerRatePercentage: $this->percentage(
                    $answerCount,
                    $participantOpportunityCount,
                ),
                averageResponseTimeMs: $this->roundedAverage(
                    $group['totalResponseTimeMs'],
                    $answerCount,
                ),
                averagePointsAwarded: $this->roundedAverage(
                    $group['totalPointsAwarded'],
                    $answerCount,
                ),
            );
        }

        return new QuizStatisticsDTO(
            quizId: $quizId,
            quizTitle: $quizTitle,
            quizVersion: $quizVersion,
            summary: new QuizStatisticsSummaryDTO(
                finishedSessionCount: $summary->finishedSessionCount,
                participantEntryCount: $summary->participantEntryCount,
                registeredParticipationCount:
                    $summary->registeredParticipationCount,
                guestParticipationCount: $summary->guestParticipationCount,
                uniqueRegisteredStudentCount:
                    $summary->uniqueRegisteredStudentCount,
                totalPossibleAnswerCount:
                    $summary->totalPossibleAnswerCount,
                answerCount: $summary->answerCount,
                correctAnswerCount: $summary->correctAnswerCount,
                incorrectAnswerCount: $summary->incorrectAnswerCount,
                unansweredCount: max(0, $summary->unansweredCount),
                accuracyPercentage: $summary->accuracyPercentage,
                answerRatePercentage: $summary->answerRatePercentage,
                highestScore: $summary->highestScore,
                averageScore: $summary->averageScore,
                averageParticipantsPerSession:
                    $summary->averageParticipantsPerSession,
            ),
            questions: $questions,
        );
    }

    private function percentage(
        int $numerator,
        int $denominator,
    ): ?float {
        if ($denominator === 0) {
            return null;
        }

        return round(
            ($numerator / $denominator) * 100,
            2,
            PHP_ROUND_HALF_UP,
        );
    }

    private function roundedAverage(
        int $total,
        int $count,
    ): ?int {
        if ($count === 0) {
            return null;
        }

        return (int) round(
            $total / $count,
            0,
            PHP_ROUND_HALF_UP,
        );
    }
}
