<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\DTO\FinalSessionLeaderboardEntryDTO;
use CodeLandQuiz\DTO\QuizSessionHistoryItemDTO;
use CodeLandQuiz\DTO\QuizSessionHistoryPageDTO;
use CodeLandQuiz\DTO\QuizSessionItemDTO;
use CodeLandQuiz\DTO\QuizSessionReportDTO;
use CodeLandQuiz\DTO\SessionReportParticipantAnswerDTO;
use CodeLandQuiz\DTO\SessionReportParticipantDTO;
use CodeLandQuiz\DTO\SessionReportQuestionDTO;
use CodeLandQuiz\DTO\SessionReportQuestionOptionDTO;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\QuizSession\Exception\QuizSessionNotFoundException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionReportNotAvailableException;
use CodeLandQuiz\QuizSession\Http\QuizSessionHistoryQueryRequest;
use CodeLandQuiz\QuizSession\QuizSessionHistoryService;
use DateTimeImmutable;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Throwable;

final class QuizSessionHistoryController
{
    public function __construct(
        private readonly QuizSessionHistoryService $historyService,
        private readonly ResponseFactory $responseFactory,
    ) {
    }

    public function list(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $query = QuizSessionHistoryQueryRequest::from($request);
            $result = $this->historyService->listSessions($query);

            $this->responseFactory->json(
                $response,
                $this->historyPageResponse($result),
            );
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (Throwable) {
            $this->responseFactory->error(
                $response,
                'Internal server error.',
                500,
            );
        }
    }

    public function report(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $sessionId = $context->getRouteInt('id');
            $report = $this->historyService->getSessionReport($sessionId);

            $this->responseFactory->json(
                $response,
                $this->reportResponse($report),
            );
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (QuizSessionNotFoundException) {
            $this->responseFactory->error(
                $response,
                'Quiz session was not found.',
                404,
            );
        } catch (QuizSessionReportNotAvailableException) {
            $this->responseFactory->error(
                $response,
                'Quiz session results are available only after the session has finished.',
                409,
            );
        } catch (Throwable) {
            $this->responseFactory->error(
                $response,
                'Internal server error.',
                500,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function historyPageResponse(
        QuizSessionHistoryPageDTO $page,
    ): array {
        return [
            'sessions' => array_map(
                $this->historyItemResponse(...),
                $page->items,
            ),
            'pagination' => [
                'pageIndex' => $page->pageIndex,
                'pageSize' => $page->pageSize,
                'totalItems' => $page->totalItems,
                'totalPages' => $page->totalPages,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function historyItemResponse(
        QuizSessionHistoryItemDTO $session,
    ): array {
        return [
            'id' => $session->id,
            'quizId' => $session->quizId,
            'quiz' => [
                'title' => $session->quizTitle,
                'version' => $session->quizVersion,
            ],
            'host' => [
                'id' => $session->hostUserId,
                'name' => $session->hostUserName,
            ],
            'gamePin' => $session->gamePin,
            'status' => $session->status->value,
            'questionCount' => $session->questionCount,
            'participantCount' => $session->participantCount,
            'removedParticipantCount' =>
                $session->removedParticipantCount,
            'startedAt' => $this->formatDateTime($session->startedAt),
            'endedAt' => $this->formatDateTime($session->endedAt),
            'createdAt' => $session->createdAt->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportResponse(QuizSessionReportDTO $report): array
    {
        return [
            'session' => $this->sessionResponse($report->session),
            'summary' => [
                'participantCount' => $report->summary->participantCount,
                'removedParticipantCount' =>
                    $report->summary->removedParticipantCount,
                'totalAnswerCount' => $report->summary->totalAnswerCount,
                'totalCorrectAnswerCount' =>
                    $report->summary->totalCorrectAnswerCount,
                'highestScore' => $report->summary->highestScore,
                'averageScore' => $report->summary->averageScore,
            ],
            'leaderboard' => array_map(
                $this->leaderboardEntryResponse(...),
                $report->leaderboard,
            ),
            'questions' => array_map(
                $this->questionResponse(...),
                $report->questions,
            ),
            'participants' => array_map(
                $this->participantResponse(...),
                $report->participants,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionResponse(QuizSessionItemDTO $session): array
    {
        return [
            'id' => $session->id,
            'quizId' => $session->quizId,
            'quiz' => [
                'title' => $session->quizTitle,
                'version' => $session->quizVersion,
            ],
            'host' => [
                'id' => $session->hostUserId,
                'name' => $session->hostUserName,
            ],
            'gamePin' => $session->gamePin,
            'status' => $session->status->value,
            'currentQuestionOrder' => $session->currentQuestionOrder,
            'currentQuestionStartedAt' => $this->formatDateTime(
                $session->currentQuestionStartedAt,
            ),
            'currentQuestionDeadline' => $this->formatDateTime(
                $session->currentQuestionDeadline,
            ),
            'currentQuestionClosedAt' => $this->formatDateTime(
                $session->currentQuestionClosedAt,
            ),
            'joinDeadline' => $this->formatDateTime($session->joinDeadline),
            'startedAt' => $this->formatDateTime($session->startedAt),
            'endedAt' => $this->formatDateTime($session->endedAt),
            'createdAt' => $session->createdAt->format(DATE_ATOM),
            'questionCount' => $session->questionCount,
            'participantCount' => $session->participantCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leaderboardEntryResponse(
        FinalSessionLeaderboardEntryDTO $entry,
    ): array {
        return [
            'rank' => $entry->rank,
            'participantId' => $entry->participantId,
            'participantType' => $entry->participantType->value,
            'nickname' => $entry->nickname,
            'avatarKey' => $entry->avatarKey,
            'totalScore' => $entry->totalScore,
            'answerCount' => $entry->answerCount,
            'correctAnswerCount' => $entry->correctAnswerCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function questionResponse(SessionReportQuestionDTO $question): array
    {
        return [
            'id' => $question->id,
            'questionText' => $question->questionText,
            'questionType' => $question->questionType->value,
            'imagePath' => $question->imagePath,
            'timeLimitSeconds' => $question->timeLimitSeconds,
            'maxPoints' => $question->maxPoints,
            'questionOrder' => $question->questionOrder,
            'options' => array_map(
                $this->questionOptionResponse(...),
                $question->options,
            ),
            'stats' => [
                'participantCount' => $question->stats->participantCount,
                'answerCount' => $question->stats->answerCount,
                'correctAnswerCount' =>
                    $question->stats->correctAnswerCount,
                'incorrectAnswerCount' =>
                    $question->stats->incorrectAnswerCount,
                'unansweredCount' => $question->stats->unansweredCount,
                'averageResponseTimeMs' =>
                    $question->stats->averageResponseTimeMs,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function questionOptionResponse(
        SessionReportQuestionOptionDTO $option,
    ): array {
        return [
            'id' => $option->id,
            'optionText' => $option->optionText,
            'isCorrect' => $option->isCorrect,
            'optionOrder' => $option->optionOrder,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function participantResponse(
        SessionReportParticipantDTO $participant,
    ): array {
        $student = null;

        if ($participant->studentId !== null) {
            $student = [
                'id' => $participant->studentId,
                'firstName' => $participant->studentFirstName,
                'lastName' => $participant->studentLastName,
                'username' => $participant->studentUsername,
            ];
        }

        return [
            'participantId' => $participant->participantId,
            'participantType' => $participant->participantType->value,
            'student' => $student,
            'nickname' => $participant->nickname,
            'avatarKey' => $participant->avatarKey,
            'totalScore' => $participant->totalScore,
            'isRemoved' => $participant->isRemoved,
            'removedAt' => $this->formatDateTime($participant->removedAt),
            'finalRank' => $participant->finalRank,
            'answerCount' => $participant->answerCount,
            'correctAnswerCount' => $participant->correctAnswerCount,
            'answers' => array_map(
                $this->participantAnswerResponse(...),
                $participant->answers,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function participantAnswerResponse(
        SessionReportParticipantAnswerDTO $answer,
    ): array {
        return [
            'sessionQuestionId' => $answer->sessionQuestionId,
            'questionOrder' => $answer->questionOrder,
            'answered' => $answer->answered,
            'selectedOptionIds' => $answer->selectedOptionIds,
            'isCorrect' => $answer->isCorrect,
            'responseTimeMs' => $answer->responseTimeMs,
            'pointsAwarded' => $answer->pointsAwarded,
            'answeredAt' => $this->formatDateTime($answer->answeredAt),
        ];
    }

    private function formatDateTime(?DateTimeImmutable $dateTime): ?string
    {
        if ($dateTime === null) {
            return null;
        }

        return $dateTime->format(DATE_ATOM);
    }
}
