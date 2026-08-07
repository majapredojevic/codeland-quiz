<?php

declare(strict_types=1);

namespace CodeLandQuiz\Student;

use CodeLandQuiz\DTO\StudentItemDTO;
use CodeLandQuiz\DTO\StudentQuizStatisticsDTO;
use CodeLandQuiz\DTO\StudentSessionPerformanceDTO;
use CodeLandQuiz\DTO\StudentStatisticsDTO;
use CodeLandQuiz\DTO\StudentStatisticsSummaryDTO;
use CodeLandQuiz\Model\StudentSessionPerformanceOverview;
use CodeLandQuiz\Repository\StudentStatisticsRepository;

final readonly class StudentStatisticsAssembler
{
    public function __construct(
        private StudentStatisticsRepository $statistics,
    ) {
    }

    public function assemble(
        StudentItemDTO $student,
    ): StudentStatisticsDTO {
        $performances = $this->statistics->findAllPerformances($student->id);
        $summaryAggregate = $this->emptyAggregate();
        $quizIds = [];
        $quizGroups = [];

        foreach ($performances as $performance) {
            $normalized = $this->toPerformanceDTO($performance);

            $this->addPerformance(
                $summaryAggregate,
                $performance,
                $normalized,
            );

            $quizIds[$performance->quizId] = true;

            if (!isset($quizGroups[$performance->quizId])) {
                $quizGroups[$performance->quizId] = [
                    'quizId' => $performance->quizId,
                    'quizTitle' => $performance->quizTitle,
                    'quizVersion' => $performance->quizVersion,
                    'aggregate' => $this->emptyAggregate(),
                ];
            }

            $group = &$quizGroups[$performance->quizId];
            $group['quizTitle'] = $performance->quizTitle;
            $group['quizVersion'] = $performance->quizVersion;
            $this->addPerformance(
                $group['aggregate'],
                $performance,
                $normalized,
            );
            unset($group);
        }

        $quizzes = [];

        foreach ($quizGroups as $group) {
            $aggregate = $group['aggregate'];

            $quizzes[] = new StudentQuizStatisticsDTO(
                quizId: $group['quizId'],
                quizTitle: $group['quizTitle'],
                quizVersion: $group['quizVersion'],
                finishedSessionCount: $aggregate['sessionCount'],
                totalPossibleAnswerCount: $aggregate['questionCount'],
                answerCount: $aggregate['answerCount'],
                correctAnswerCount: $aggregate['correctAnswerCount'],
                incorrectAnswerCount: max(
                    0,
                    $aggregate['answerCount']
                        - $aggregate['correctAnswerCount'],
                ),
                unansweredCount: max(
                    0,
                    $aggregate['questionCount']
                        - $aggregate['answerCount'],
                ),
                accuracyPercentage: $this->percentage(
                    $aggregate['correctAnswerCount'],
                    $aggregate['answerCount'],
                ),
                answerRatePercentage: $this->percentage(
                    $aggregate['answerCount'],
                    $aggregate['questionCount'],
                ),
                totalScore: $aggregate['totalScore'],
                averageScore: $this->roundedAverage(
                    $aggregate['totalScore'],
                    $aggregate['sessionCount'],
                ),
                averageScorePercentage: $this->percentageAverage(
                    $aggregate['scorePercentageTotal'],
                    $aggregate['scorePercentageCount'],
                ),
                highestScore: $aggregate['highestScore'] ?? 0,
                highestScorePercentage:
                    $aggregate['highestScorePercentage'],
                averageResponseTimeMs: $this->roundedAverage(
                    $aggregate['totalResponseTimeMs'],
                    $aggregate['answerCount'],
                ),
                topThreeCount: $aggregate['topThreeCount'],
                firstPlaceCount: $aggregate['firstPlaceCount'],
            );
        }

        usort(
            $quizzes,
            static function (
                StudentQuizStatisticsDTO $left,
                StudentQuizStatisticsDTO $right,
            ): int {
                $sessionComparison = $right->finishedSessionCount
                    <=> $left->finishedSessionCount;

                if ($sessionComparison !== 0) {
                    return $sessionComparison;
                }

                $titleComparison = strcmp(
                    $left->quizTitle,
                    $right->quizTitle,
                );

                if ($titleComparison !== 0) {
                    return $titleComparison;
                }

                $versionComparison = $left->quizVersion
                    <=> $right->quizVersion;

                if ($versionComparison !== 0) {
                    return $versionComparison;
                }

                return $left->quizId <=> $right->quizId;
            },
        );

        return new StudentStatisticsDTO(
            student: $student,
            summary: new StudentStatisticsSummaryDTO(
                finishedSessionCount: $summaryAggregate['sessionCount'],
                distinctQuizCount: count($quizIds),
                totalPossibleAnswerCount:
                    $summaryAggregate['questionCount'],
                answerCount: $summaryAggregate['answerCount'],
                correctAnswerCount:
                    $summaryAggregate['correctAnswerCount'],
                incorrectAnswerCount: max(
                    0,
                    $summaryAggregate['answerCount']
                        - $summaryAggregate['correctAnswerCount'],
                ),
                unansweredCount: max(
                    0,
                    $summaryAggregate['questionCount']
                        - $summaryAggregate['answerCount'],
                ),
                accuracyPercentage: $this->percentage(
                    $summaryAggregate['correctAnswerCount'],
                    $summaryAggregate['answerCount'],
                ),
                answerRatePercentage: $this->percentage(
                    $summaryAggregate['answerCount'],
                    $summaryAggregate['questionCount'],
                ),
                totalScore: $summaryAggregate['totalScore'],
                averageScore: $this->roundedAverage(
                    $summaryAggregate['totalScore'],
                    $summaryAggregate['sessionCount'],
                ),
                averageScorePercentage: $this->percentageAverage(
                    $summaryAggregate['scorePercentageTotal'],
                    $summaryAggregate['scorePercentageCount'],
                ),
                highestScore: $summaryAggregate['highestScore'] ?? 0,
                highestScorePercentage:
                    $summaryAggregate['highestScorePercentage'],
                averageResponseTimeMs: $this->roundedAverage(
                    $summaryAggregate['totalResponseTimeMs'],
                    $summaryAggregate['answerCount'],
                ),
                topThreeCount: $summaryAggregate['topThreeCount'],
                firstPlaceCount: $summaryAggregate['firstPlaceCount'],
            ),
            quizzes: $quizzes,
        );
    }

    public function toPerformanceDTO(
        StudentSessionPerformanceOverview $performance,
    ): StudentSessionPerformanceDTO {
        $scorePercentage = $performance->maxPossibleScore === 0
            ? null
            : round(
                max(
                    0.0,
                    min(
                        100.0,
                        ($performance->totalScore
                            / $performance->maxPossibleScore) * 100,
                    ),
                ),
                2,
                PHP_ROUND_HALF_UP,
            );

        return new StudentSessionPerformanceDTO(
            sessionId: $performance->sessionId,
            quizId: $performance->quizId,
            quizTitle: $performance->quizTitle,
            quizVersion: $performance->quizVersion,
            startedAt: $performance->sessionStartedAt,
            endedAt: $performance->sessionEndedAt,
            questionCount: $performance->questionCount,
            maxPossibleScore: $performance->maxPossibleScore,
            totalScore: $performance->totalScore,
            scorePercentage: $scorePercentage,
            answerCount: $performance->answerCount,
            correctAnswerCount: $performance->correctAnswerCount,
            incorrectAnswerCount: max(
                0,
                $performance->answerCount
                    - $performance->correctAnswerCount,
            ),
            unansweredCount: max(
                0,
                $performance->questionCount - $performance->answerCount,
            ),
            accuracyPercentage: $this->percentage(
                $performance->correctAnswerCount,
                $performance->answerCount,
            ),
            answerRatePercentage: $this->percentage(
                $performance->answerCount,
                $performance->questionCount,
            ),
            averageResponseTimeMs: $this->roundedAverage(
                $performance->totalResponseTimeMs,
                $performance->answerCount,
            ),
            participantCount: $performance->participantCount,
            finalRank: $performance->finalRank,
        );
    }

    /**
     * @return array{
     *     sessionCount: int,
     *     questionCount: int,
     *     answerCount: int,
     *     correctAnswerCount: int,
     *     totalScore: int,
     *     highestScore: ?int,
     *     scorePercentageTotal: float,
     *     scorePercentageCount: int,
     *     highestScorePercentage: ?float,
     *     totalResponseTimeMs: int,
     *     topThreeCount: int,
     *     firstPlaceCount: int
     * }
     */
    private function emptyAggregate(): array
    {
        return [
            'sessionCount' => 0,
            'questionCount' => 0,
            'answerCount' => 0,
            'correctAnswerCount' => 0,
            'totalScore' => 0,
            'highestScore' => null,
            'scorePercentageTotal' => 0.0,
            'scorePercentageCount' => 0,
            'highestScorePercentage' => null,
            'totalResponseTimeMs' => 0,
            'topThreeCount' => 0,
            'firstPlaceCount' => 0,
        ];
    }

    /**
     * @param array{
     *     sessionCount: int,
     *     questionCount: int,
     *     answerCount: int,
     *     correctAnswerCount: int,
     *     totalScore: int,
     *     highestScore: ?int,
     *     scorePercentageTotal: float,
     *     scorePercentageCount: int,
     *     highestScorePercentage: ?float,
     *     totalResponseTimeMs: int,
     *     topThreeCount: int,
     *     firstPlaceCount: int
     * } $aggregate
     */
    private function addPerformance(
        array &$aggregate,
        StudentSessionPerformanceOverview $performance,
        StudentSessionPerformanceDTO $normalized,
    ): void {
        ++$aggregate['sessionCount'];
        $aggregate['questionCount'] += $performance->questionCount;
        $aggregate['answerCount'] += $performance->answerCount;
        $aggregate['correctAnswerCount'] +=
            $performance->correctAnswerCount;
        $aggregate['totalScore'] += $performance->totalScore;
        $aggregate['highestScore'] = $aggregate['highestScore'] === null
            ? $performance->totalScore
            : max($aggregate['highestScore'], $performance->totalScore);
        $aggregate['totalResponseTimeMs'] +=
            $performance->totalResponseTimeMs;

        if ($normalized->scorePercentage !== null) {
            $aggregate['scorePercentageTotal'] +=
                $normalized->scorePercentage;
            ++$aggregate['scorePercentageCount'];
            $aggregate['highestScorePercentage'] =
                $aggregate['highestScorePercentage'] === null
                    ? $normalized->scorePercentage
                    : max(
                        $aggregate['highestScorePercentage'],
                        $normalized->scorePercentage,
                    );
        }

        if ($performance->finalRank <= 3) {
            ++$aggregate['topThreeCount'];
        }

        if ($performance->finalRank === 1) {
            ++$aggregate['firstPlaceCount'];
        }
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

    private function percentageAverage(
        float $total,
        int $count,
    ): ?float {
        if ($count === 0) {
            return null;
        }

        return round(
            $total / $count,
            2,
            PHP_ROUND_HALF_UP,
        );
    }
}
