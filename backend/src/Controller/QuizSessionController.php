<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\DTO\ClosedSessionQuestionStateDTO;
use CodeLandQuiz\DTO\FinalQuizSessionResultDTO;
use CodeLandQuiz\DTO\FinalSessionLeaderboardEntryDTO;
use CodeLandQuiz\DTO\PublicSessionQuestionDTO;
use CodeLandQuiz\DTO\PublicSessionQuestionOptionDTO;
use CodeLandQuiz\DTO\QuizSessionItemDTO;
use CodeLandQuiz\DTO\SessionLeaderboardEntryDTO;
use CodeLandQuiz\DTO\SessionParticipantAdminDTO;
use CodeLandQuiz\DTO\SessionParticipantListDTO;
use CodeLandQuiz\DTO\SessionQuestionParticipantResultDTO;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Quiz\Exception\QuizNotFoundException;
use CodeLandQuiz\QuizSession\Exception\GamePinGenerationFailedException;
use CodeLandQuiz\QuizSession\Exception\QuizInactiveException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionCannotStartException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionCannotFinishException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionCurrentQuestionNotClosedException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionLastQuestionNotClosedException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionNextQuestionCannotStartException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionNoNextQuestionException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionNotFoundException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionQuestionCannotCloseException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionQuestionStateConflictException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionStateConflictException;
use CodeLandQuiz\QuizSession\Exception\SessionParticipantNotFoundException;
use CodeLandQuiz\QuizSession\Exception\SessionParticipantRemovalNotAllowedException;
use CodeLandQuiz\QuizSession\QuizSessionService;
use CodeLandQuiz\WebSocket\ClosedQuestionWebSocketNotifier;
use CodeLandQuiz\WebSocket\FinishedSessionWebSocketNotifier;
use CodeLandQuiz\WebSocket\ParticipantRemovalWebSocketNotifier;
use CodeLandQuiz\WebSocket\SessionWebSocketBroadcaster;
use CodeLandQuiz\WebSocket\SessionWebSocketPayloadMapper;
use DateTimeImmutable;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Throwable;

final class QuizSessionController
{
    public function __construct(
        private readonly QuizSessionService $quizSessionService,
        private readonly ResponseFactory $responseFactory,
        private readonly SessionWebSocketBroadcaster $sessionWebSocketBroadcaster,
        private readonly SessionWebSocketPayloadMapper $webSocketPayloadMapper,
        private readonly ClosedQuestionWebSocketNotifier $closedQuestionNotifier,
        private readonly FinishedSessionWebSocketNotifier $finishedSessionNotifier,
        private readonly ParticipantRemovalWebSocketNotifier $participantRemovalNotifier,
    ) {
    }

    public function create(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $quizId = $context->getRouteInt('quizId');
            $actorUserId = $context->getCurrentUser()->id;
            $session = $this->quizSessionService->createSession(
                $actorUserId,
                $quizId,
            );

            $this->responseFactory->json($response, [
                'session' => $this->sessionResponse($session),
            ], 201);
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (QuizNotFoundException) {
            $this->responseFactory->error(
                $response,
                'Quiz was not found.',
                404,
            );
        } catch (QuizInactiveException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                409,
            );
        } catch (GamePinGenerationFailedException) {
            $this->responseFactory->error(
                $response,
                'A unique game PIN could not be generated.',
                500,
            );
        } catch (Throwable) {
            $this->responseFactory->error(
                $response,
                'Internal server error.',
                500,
            );
        }
    }

    public function get(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $sessionId = $context->getRouteInt('id');
            $state = $this->quizSessionService
                ->getSessionPresentationState($sessionId);

            $this->responseFactory->json($response, [
                'session' => $this->sessionResponse($state->session),
                'currentQuestion' => $state->currentQuestion === null
                    ? null
                    : $this->questionResponse($state->currentQuestion),
                'questionResult' => $state->questionResult === null
                    ? null
                    : $this->closedQuestionResponse(
                        $state->questionResult,
                    ),
                'finalResult' => $state->finalResult === null
                    ? null
                    : $this->finalResultResponse($state->finalResult),
            ]);
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
        } catch (Throwable) {
            $this->responseFactory->error(
                $response,
                'Internal server error.',
                500,
            );
        }
    }

    public function listParticipants(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $sessionId = $context->getRouteInt('id');
            $result = $this->quizSessionService
                ->listSessionParticipants($sessionId);

            $this->responseFactory->json(
                $response,
                $this->participantListResponse($result),
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
        } catch (Throwable) {
            $this->responseFactory->error(
                $response,
                'Internal server error.',
                500,
            );
        }
    }

    public function removeParticipant(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $sessionId = $context->getRouteInt('id');
            $participantId = $context->getRouteInt('participantId');
            $actorUserId = $context->getCurrentUser()->id;
            $result = $this->quizSessionService->removeSessionParticipant(
                actorUserId: $actorUserId,
                sessionId: $sessionId,
                participantId: $participantId,
            );

            if ($result->stateChanged) {
                $this->participantRemovalNotifier->notifyAndDisconnect(
                    $result->participantId,
                );
            }

            $response->status(204);
            $response->end();
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
        } catch (SessionParticipantNotFoundException) {
            $this->responseFactory->error(
                $response,
                'Session participant was not found.',
                404,
            );
        } catch (SessionParticipantRemovalNotAllowedException) {
            $this->responseFactory->error(
                $response,
                'Participants cannot be removed from a finished quiz session.',
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

    public function start(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $sessionId = $context->getRouteInt('id');
            $actorUserId = $context->getCurrentUser()->id;
            $result = $this->quizSessionService->startSession(
                actorUserId: $actorUserId,
                sessionId: $sessionId,
            );

            if ($result->stateChanged) {
                $this->sessionWebSocketBroadcaster->broadcast(
                    sessionId: $result->session->id,
                    type: 'GAME_STARTED',
                    payload: $this->webSocketPayloadMapper->gameStarted(
                        $result,
                    ),
                );
                $this->sessionWebSocketBroadcaster->broadcast(
                    sessionId: $result->session->id,
                    type: 'QUESTION_STARTED',
                    payload: $this->webSocketPayloadMapper->questionStarted(
                        $result,
                    ),
                );
            }

            $this->responseFactory->json($response, [
                'session' => $this->sessionResponse($result->session),
                'currentQuestion' => $this->questionResponse(
                    $result->currentQuestion,
                ),
                'questionCount' => $result->questionCount,
                'stateChanged' => $result->stateChanged,
            ]);
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
        } catch (
            QuizSessionCannotStartException
            | QuizSessionStateConflictException $exception
        ) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
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

    public function closeCurrentQuestion(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $sessionId = $context->getRouteInt('id');
            $actorUserId = $context->getCurrentUser()->id;
            $result = $this->quizSessionService->closeCurrentQuestion(
                actorUserId: $actorUserId,
                sessionId: $sessionId,
            );

            if ($result->stateChanged) {
                $this->closedQuestionNotifier->notify(
                    sessionId: $result->session->id,
                    state: $result->closedQuestion,
                );
            }

            $this->responseFactory->json($response, [
                'session' => $this->sessionResponse($result->session),
                'questionResult' => $this->closedQuestionResponse(
                    $result->closedQuestion,
                ),
                'stateChanged' => $result->stateChanged,
            ]);
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
        } catch (
            QuizSessionQuestionCannotCloseException
            | QuizSessionQuestionStateConflictException $exception
        ) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
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

    public function startNextQuestion(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $sessionId = $context->getRouteInt('id');
            $actorUserId = $context->getCurrentUser()->id;
            $result = $this->quizSessionService->startNextQuestion(
                actorUserId: $actorUserId,
                sessionId: $sessionId,
            );

            $this->sessionWebSocketBroadcaster->broadcast(
                sessionId: $result->session->id,
                type: 'QUESTION_STARTED',
                payload: $this->webSocketPayloadMapper->nextQuestionStarted(
                    $result,
                ),
            );

            $this->responseFactory->json($response, [
                'session' => $this->sessionResponse($result->session),
                'currentQuestion' => $this->questionResponse(
                    $result->currentQuestion,
                ),
                'questionCount' => $result->questionCount,
                'previousQuestionOrder' => $result->previousQuestionOrder,
            ]);
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
        } catch (
            QuizSessionNextQuestionCannotStartException
            | QuizSessionCurrentQuestionNotClosedException
            | QuizSessionNoNextQuestionException $exception
        ) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
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

    public function finish(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $sessionId = $context->getRouteInt('id');
            $actorUserId = $context->getCurrentUser()->id;
            $result = $this->quizSessionService->finishSession(
                actorUserId: $actorUserId,
                sessionId: $sessionId,
            );

            if ($result->stateChanged) {
                $this->finishedSessionNotifier->notify($result);
            }

            $this->responseFactory->json($response, [
                'session' => $this->sessionResponse($result->session),
                'finalResult' => $this->finalResultResponse($result),
                'stateChanged' => $result->stateChanged,
            ]);
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
        } catch (
            QuizSessionCannotFinishException
            | QuizSessionLastQuestionNotClosedException $exception
        ) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
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
    private function participantListResponse(
        SessionParticipantListDTO $result,
    ): array {
        return [
            'session' => [
                'id' => $result->sessionId,
                'status' => $result->sessionStatus->value,
                'currentQuestionOrder' => $result->currentQuestionOrder,
            ],
            'participants' => array_map(
                $this->adminParticipantResponse(...),
                $result->participants,
            ),
            'participantCount' => $result->participantCount,
            'connectedParticipantCount' =>
                $result->connectedParticipantCount,
            'answeredCurrentQuestionCount' =>
                $result->answeredCurrentQuestionCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminParticipantResponse(
        SessionParticipantAdminDTO $participant,
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
            'id' => $participant->id,
            'participantType' => $participant->participantType->value,
            'student' => $student,
            'nickname' => $participant->nickname,
            'avatarKey' => $participant->avatarKey,
            'totalScore' => $participant->totalScore,
            'isConnected' => $participant->isConnected,
            'disconnectedAt' => $this->formatDateTime(
                $participant->disconnectedAt,
            ),
            'joinedAt' => $participant->joinedAt->format(DATE_ATOM),
            'hasAnsweredCurrentQuestion' =>
                $participant->hasAnsweredCurrentQuestion,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function questionResponse(
        PublicSessionQuestionDTO $question,
    ): array {
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function questionOptionResponse(
        PublicSessionQuestionOptionDTO $option,
    ): array {
        return [
            'id' => $option->id,
            'optionText' => $option->optionText,
            'optionOrder' => $option->optionOrder,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function closedQuestionResponse(
        ClosedSessionQuestionStateDTO $state,
    ): array {
        return [
            'question' => $this->questionResponse($state->question),
            'closedAt' => $state->closedAt->format(DATE_ATOM),
            'correctOptionIds' => $state->correctOptionIds,
            'stats' => [
                'participantCount' => $state->stats->participantCount,
                'answerCount' => $state->stats->answerCount,
                'correctAnswerCount' => $state->stats->correctAnswerCount,
                'incorrectAnswerCount' => $state->stats->incorrectAnswerCount,
                'unansweredCount' => $state->stats->unansweredCount,
            ],
            'participantResults' => array_map(
                $this->participantResultResponse(...),
                $state->participantResults,
            ),
            'leaderboard' => array_map(
                $this->leaderboardEntryResponse(...),
                $state->leaderboard,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function participantResultResponse(
        SessionQuestionParticipantResultDTO $result,
    ): array {
        return [
            'participantId' => $result->participantId,
            'participantType' => $result->participantType->value,
            'nickname' => $result->nickname,
            'avatarKey' => $result->avatarKey,
            'answered' => $result->answered,
            'selectedOptionIds' => $result->selectedOptionIds,
            'isCorrect' => $result->isCorrect,
            'responseTimeMs' => $result->responseTimeMs,
            'pointsAwarded' => $result->pointsAwarded,
            'totalScore' => $result->totalScore,
            'answeredAt' => $this->formatDateTime($result->answeredAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leaderboardEntryResponse(
        SessionLeaderboardEntryDTO $entry,
    ): array {
        return [
            'rank' => $entry->rank,
            'participantId' => $entry->participantId,
            'participantType' => $entry->participantType->value,
            'nickname' => $entry->nickname,
            'avatarKey' => $entry->avatarKey,
            'totalScore' => $entry->totalScore,
            'pointsAwardedThisQuestion' =>
                $entry->pointsAwardedThisQuestion,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finalResultResponse(
        FinalQuizSessionResultDTO $result,
    ): array {
        return [
            'participantCount' => $result->participantCount,
            'totalAnswerCount' => $result->totalAnswerCount,
            'totalCorrectAnswerCount' => $result->totalCorrectAnswerCount,
            'topThree' => array_map(
                $this->finalLeaderboardEntryResponse(...),
                $result->topThree,
            ),
            'leaderboard' => array_map(
                $this->finalLeaderboardEntryResponse(...),
                $result->leaderboard,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finalLeaderboardEntryResponse(
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

    private function formatDateTime(?DateTimeImmutable $dateTime): ?string
    {
        if ($dateTime === null) {
            return null;
        }

        return $dateTime->format(DATE_ATOM);
    }
}
