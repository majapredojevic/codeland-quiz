<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\DTO\PublicSessionQuestionDTO;
use CodeLandQuiz\DTO\PublicSessionQuestionOptionDTO;
use CodeLandQuiz\DTO\QuizSessionItemDTO;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Quiz\Exception\QuizNotFoundException;
use CodeLandQuiz\QuizSession\Exception\GamePinGenerationFailedException;
use CodeLandQuiz\QuizSession\Exception\QuizInactiveException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionCannotStartException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionNotFoundException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionStateConflictException;
use CodeLandQuiz\QuizSession\QuizSessionService;
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
            $session = $this->quizSessionService->getSession($sessionId);

            $this->responseFactory->json($response, [
                'session' => $this->sessionResponse($session),
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

    private function formatDateTime(?DateTimeImmutable $dateTime): ?string
    {
        if ($dateTime === null) {
            return null;
        }

        return $dateTime->format(DATE_ATOM);
    }
}
