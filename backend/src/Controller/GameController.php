<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\DTO\GameSessionPreviewDTO;
use CodeLandQuiz\DTO\JoinGameResultDTO;
use CodeLandQuiz\DTO\SessionParticipantItemDTO;
use CodeLandQuiz\Game\AvatarCatalog;
use CodeLandQuiz\Game\Exception\ActiveStudentNotFoundException;
use CodeLandQuiz\Game\Exception\GameJoinClosedException;
use CodeLandQuiz\Game\Exception\GameSessionNotFoundException;
use CodeLandQuiz\Game\Exception\ParticipantAlreadyJoinedException;
use CodeLandQuiz\Game\Exception\ParticipantNicknameAlreadyExistsException;
use CodeLandQuiz\Game\GameService;
use CodeLandQuiz\Game\Http\GamePinRoute;
use CodeLandQuiz\Game\Http\JoinGameRequest;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use DateTimeImmutable;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Throwable;

final class GameController
{
    public function __construct(
        private readonly GameService $gameService,
        private readonly AvatarCatalog $avatarCatalog,
        private readonly ResponseFactory $responseFactory,
    ) {
    }

    public function preview(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $gamePin = GamePinRoute::fromContext($context);
            $preview = $this->gameService->getSessionPreview($gamePin);

            $this->responseFactory->json($response, [
                'session' => $this->sessionPreviewResponse($preview),
                'avatarKeys' => $this->avatarCatalog->all(),
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (GameSessionNotFoundException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
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

    public function join(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $dto = JoinGameRequest::from($request);
            $result = $this->gameService->joinGame($dto);

            $this->responseFactory->json(
                $response,
                $this->joinGameResponse($result),
                201,
            );
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (
            GameSessionNotFoundException
            | ActiveStudentNotFoundException $exception
        ) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                404,
            );
        } catch (
            GameJoinClosedException
            | ParticipantAlreadyJoinedException
            | ParticipantNicknameAlreadyExistsException $exception
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
    private function sessionPreviewResponse(
        GameSessionPreviewDTO $preview,
    ): array {
        return [
            'quiz' => [
                'title' => $preview->quizTitle,
                'version' => $preview->quizVersion,
            ],
            'status' => $preview->status->value,
            'participantCount' => $preview->participantCount,
            'canJoin' => $preview->canJoin,
            'joinDeadline' => $this->formatDateTime($preview->joinDeadline),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function joinGameResponse(JoinGameResultDTO $result): array
    {
        return [
            'participant' => $this->participantResponse($result->participant),
            'session' => [
                'id' => $result->sessionId,
                'quiz' => [
                    'title' => $result->quizTitle,
                    'version' => $result->quizVersion,
                ],
                'gamePin' => $result->gamePin,
                'status' => $result->status->value,
            ],
            'participantToken' => $result->participantToken->token,
            'participantTokenExpiresAt' => $result
                ->participantToken
                ->expiresAt
                ->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function participantResponse(
        SessionParticipantItemDTO $participant,
    ): array {
        return [
            'id' => $participant->id,
            'sessionId' => $participant->sessionId,
            'participantType' => $participant->participantType->value,
            'studentId' => $participant->studentId,
            'nickname' => $participant->nickname,
            'avatarKey' => $participant->avatarKey,
            'totalScore' => $participant->totalScore,
            'isConnected' => $participant->isConnected,
            'joinedAt' => $participant->joinedAt->format(DATE_ATOM),
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
