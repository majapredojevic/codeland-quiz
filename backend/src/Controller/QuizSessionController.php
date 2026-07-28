<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\DTO\QuizSessionItemDTO;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Quiz\Exception\QuizNotFoundException;
use CodeLandQuiz\QuizSession\Exception\GamePinGenerationFailedException;
use CodeLandQuiz\QuizSession\Exception\QuizInactiveException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionNotFoundException;
use CodeLandQuiz\QuizSession\QuizSessionService;
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
            'joinDeadline' => $this->formatDateTime($session->joinDeadline),
            'startedAt' => $this->formatDateTime($session->startedAt),
            'endedAt' => $this->formatDateTime($session->endedAt),
            'createdAt' => $session->createdAt->format(DATE_ATOM),
            'questionCount' => $session->questionCount,
            'participantCount' => $session->participantCount,
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
