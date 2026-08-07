<?php

declare(strict_types=1);

namespace CodeLandQuiz\QuizSession;

use CodeLandQuiz\DTO\QuizSessionItemDTO;
use CodeLandQuiz\DTO\QuizSessionReportDTO;
use CodeLandQuiz\DTO\QuizSessionReportSummaryDTO;
use CodeLandQuiz\DTO\SessionReportParticipantAnswerDTO;
use CodeLandQuiz\DTO\SessionReportParticipantDTO;
use CodeLandQuiz\DTO\SessionReportQuestionDTO;
use CodeLandQuiz\DTO\SessionReportQuestionOptionDTO;
use CodeLandQuiz\DTO\SessionReportQuestionStatsDTO;
use CodeLandQuiz\Model\QuizSessionStatus;
use CodeLandQuiz\Model\SessionQuestionOptionOverview;
use CodeLandQuiz\QuizSession\Exception\QuizSessionReportNotAvailableException;
use CodeLandQuiz\Repository\QuizSessionReportRepository;

final readonly class QuizSessionReportAssembler
{
    public function __construct(
        private QuizSessionReportRepository $reports,
        private FinalQuizSessionResultAssembler $finalResultAssembler,
    ) {
    }

    public function assemble(
        QuizSessionItemDTO $session,
    ): QuizSessionReportDTO {
        if ($session->status !== QuizSessionStatus::FINISHED) {
            throw new QuizSessionReportNotAvailableException(
                'Quiz session results are available only after the session has finished.',
            );
        }

        $questions = $this->reports->findQuestions($session->id);
        $participants = $this->reports->findParticipants($session->id);
        $answers = $this->reports->findAnswers($session->id);
        $finalResult = $this->finalResultAssembler->assemble(
            $session,
            false,
        );

        $questionById = [];
        $questionStatsById = [];

        foreach ($questions as $question) {
            $questionById[$question->id] = $question;
            $questionStatsById[$question->id] = [
                'answerCount' => 0,
                'correctAnswerCount' => 0,
                'incorrectAnswerCount' => 0,
                'responseTimeTotalMs' => 0,
            ];
        }

        $participantById = [];

        foreach ($participants as $participant) {
            $participantById[$participant->participantId] = $participant;
        }

        $answersByParticipantAndQuestion = [];

        foreach ($answers as $answer) {
            if (
                !isset($questionById[$answer->sessionQuestionId])
                || !isset($participantById[$answer->participantId])
            ) {
                continue;
            }

            $answersByParticipantAndQuestion[$answer->participantId][
                $answer->sessionQuestionId
            ] = $answer;
        }

        $finalRankByParticipantId = [];

        foreach ($finalResult->leaderboard as $entry) {
            $finalRankByParticipantId[$entry->participantId] = $entry->rank;
        }

        $participantDtos = [];
        $participantCount = 0;
        $removedParticipantCount = 0;
        $totalAnswerCount = 0;
        $totalCorrectAnswerCount = 0;
        $highestScore = 0;
        $scoreTotal = 0;

        foreach ($participants as $participant) {
            $participantAnswers = [];
            $participantAnswerCount = 0;
            $participantCorrectAnswerCount = 0;

            foreach ($questions as $question) {
                $answer = $answersByParticipantAndQuestion[
                    $participant->participantId
                ][$question->id] ?? null;

                if ($answer === null) {
                    $participantAnswers[] = new SessionReportParticipantAnswerDTO(
                        sessionQuestionId: $question->id,
                        questionOrder: $question->questionOrder,
                        answered: false,
                        selectedOptionIds: [],
                        isCorrect: null,
                        responseTimeMs: null,
                        pointsAwarded: 0,
                        answeredAt: null,
                    );

                    continue;
                }

                $participantAnswerCount++;

                if ($answer->isCorrect) {
                    $participantCorrectAnswerCount++;
                }

                if (!$participant->isRemoved) {
                    $questionStats = &$questionStatsById[$question->id];
                    $questionStats['answerCount']++;
                    $questionStats['responseTimeTotalMs'] +=
                        $answer->responseTimeMs;

                    if ($answer->isCorrect) {
                        $questionStats['correctAnswerCount']++;
                    } else {
                        $questionStats['incorrectAnswerCount']++;
                    }

                    unset($questionStats);
                }

                $participantAnswers[] = new SessionReportParticipantAnswerDTO(
                    sessionQuestionId: $answer->sessionQuestionId,
                    questionOrder: $question->questionOrder,
                    answered: true,
                    selectedOptionIds: $answer->selectedOptionIds,
                    isCorrect: $answer->isCorrect,
                    responseTimeMs: $answer->responseTimeMs,
                    pointsAwarded: $answer->pointsAwarded,
                    answeredAt: $answer->answeredAt,
                );
            }

            if ($participant->isRemoved) {
                $removedParticipantCount++;
            } else {
                $participantCount++;
                $totalAnswerCount += $participantAnswerCount;
                $totalCorrectAnswerCount += $participantCorrectAnswerCount;
                $highestScore = max($highestScore, $participant->totalScore);
                $scoreTotal += $participant->totalScore;
            }

            $participantDtos[] = new SessionReportParticipantDTO(
                participantId: $participant->participantId,
                participantType: $participant->participantType,
                studentId: $participant->studentId,
                studentFirstName: $participant->studentFirstName,
                studentLastName: $participant->studentLastName,
                studentUsername: $participant->studentUsername,
                nickname: $participant->nickname,
                avatarKey: $participant->avatarKey,
                totalScore: $participant->totalScore,
                isRemoved: $participant->isRemoved,
                removedAt: $participant->removedAt,
                finalRank: $participant->isRemoved
                    ? null
                    : ($finalRankByParticipantId[
                        $participant->participantId
                    ] ?? null),
                answerCount: $participantAnswerCount,
                correctAnswerCount: $participantCorrectAnswerCount,
                answers: $participantAnswers,
            );
        }

        $questionDtos = [];

        foreach ($questions as $question) {
            $stats = $questionStatsById[$question->id];
            $answerCount = $stats['answerCount'];

            $questionDtos[] = new SessionReportQuestionDTO(
                id: $question->id,
                questionText: $question->questionText,
                questionType: $question->questionType,
                imagePath: $question->imagePath,
                timeLimitSeconds: $question->timeLimitSeconds,
                maxPoints: $question->maxPoints,
                questionOrder: $question->questionOrder,
                options: array_map(
                    static fn(
                        SessionQuestionOptionOverview $option,
                    ): SessionReportQuestionOptionDTO =>
                        new SessionReportQuestionOptionDTO(
                            id: $option->id,
                            optionText: $option->optionText,
                            isCorrect: $option->isCorrect,
                            optionOrder: $option->optionOrder,
                        ),
                    $question->options,
                ),
                stats: new SessionReportQuestionStatsDTO(
                    participantCount: $participantCount,
                    answerCount: $answerCount,
                    correctAnswerCount: $stats['correctAnswerCount'],
                    incorrectAnswerCount: $stats['incorrectAnswerCount'],
                    unansweredCount: $participantCount - $answerCount,
                    averageResponseTimeMs: $answerCount === 0
                        ? null
                        : (int) round(
                            $stats['responseTimeTotalMs'] / $answerCount,
                        ),
                ),
            );
        }

        return new QuizSessionReportDTO(
            session: $session,
            summary: new QuizSessionReportSummaryDTO(
                participantCount: $participantCount,
                removedParticipantCount: $removedParticipantCount,
                totalAnswerCount: $totalAnswerCount,
                totalCorrectAnswerCount: $totalCorrectAnswerCount,
                highestScore: $highestScore,
                averageScore: $participantCount === 0
                    ? null
                    : (int) round($scoreTotal / $participantCount),
            ),
            leaderboard: $finalResult->leaderboard,
            questions: $questionDtos,
            participants: $participantDtos,
        );
    }
}
