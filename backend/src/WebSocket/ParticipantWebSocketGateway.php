<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use CodeLandQuiz\DTO\AnswerSubmissionResultDTO;
use CodeLandQuiz\DTO\ParticipantConnectionResultDTO;
use CodeLandQuiz\DTO\SessionParticipantItemDTO;
use CodeLandQuiz\Game\AnswerSubmissionService;
use CodeLandQuiz\Game\Exception\AnswerAlreadySubmittedException;
use CodeLandQuiz\Game\Exception\AnswerDeadlineExpiredException;
use CodeLandQuiz\Game\Exception\AnswerSubmissionNotAllowedException;
use CodeLandQuiz\Game\Exception\GameSessionFinishedException;
use CodeLandQuiz\Game\Exception\InvalidParticipantTokenException;
use CodeLandQuiz\Game\Exception\InvalidSelectedOptionsException;
use CodeLandQuiz\Game\Exception\ParticipantConnectionRejectedException;
use CodeLandQuiz\Game\ParticipantConnectionService;
use CodeLandQuiz\Model\QuizSessionStatus;
use InvalidArgumentException;
use JsonException;
use OpenSwoole\Http\Request;
use OpenSwoole\Timer;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;
use stdClass;
use Throwable;

final class ParticipantWebSocketGateway implements WebSocketGateway
{
    private const AUTHENTICATION_TIMEOUT_SECONDS = 10;
    private const POLICY_VIOLATION_CLOSE_CODE = 1008;
    private const AUTHENTICATE_MESSAGE_TYPE = 'PARTICIPANT_AUTHENTICATE';
    private const ANSWER_SUBMIT_MESSAGE_TYPE = 'ANSWER_SUBMIT';

    /**
     * @var array<int, string>
     */
    private array $pendingConnectionIds = [];

    public function __construct(
        private readonly ParticipantConnectionService $participantConnectionService,
        private readonly AnswerSubmissionService $answerSubmissionService,
        private readonly ParticipantConnectionRegistry $connectionRegistry,
        private readonly WebSocketMessageEncoder $messageEncoder,
        private readonly SessionWebSocketPayloadMapper $payloadMapper,
    ) {
    }

    public function open(Server $server, Request $request): void
    {
        $fileDescriptor = (int) $request->fd;
        $connectionId = $this->connectionRegistry->registerPending(
            $fileDescriptor,
        );
        $this->pendingConnectionIds[$fileDescriptor] = $connectionId;

        $this->push($server, $fileDescriptor, 'AUTHENTICATION_REQUIRED', [
            'timeoutSeconds' => self::AUTHENTICATION_TIMEOUT_SECONDS,
        ]);

        Timer::after(
            self::AUTHENTICATION_TIMEOUT_SECONDS * 1000,
            function () use ($server, $fileDescriptor, $connectionId): void {
                try {
                    $this->closeTimedOutConnection(
                        server: $server,
                        fileDescriptor: $fileDescriptor,
                        connectionId: $connectionId,
                    );
                } catch (Throwable $throwable) {
                    error_log($throwable->getMessage());
                }
            },
        );
    }

    public function message(Server $server, Frame $frame): void
    {
        $fileDescriptor = $frame->fd;

        if ($this->connectionRegistry->isAuthenticated($fileDescriptor)) {
            $this->handleAuthenticatedMessage($server, $frame);

            return;
        }

        $connectionId = $this->pendingConnectionIds[$fileDescriptor] ?? null;

        if (
            $connectionId === null
            || !$this->connectionRegistry->isPending(
                $fileDescriptor,
                $connectionId,
            )
        ) {
            $this->failAuthentication(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'INVALID_AUTHENTICATION_MESSAGE',
                message: 'A valid participant authentication message is required.',
            );

            return;
        }

        try {
            $participantToken = $this->readParticipantToken($frame->data);
            $result = $this->participantConnectionService->authenticate(
                $participantToken,
            );
            $previousFileDescriptor = $this->connectionRegistry->authenticate(
                fileDescriptor: $fileDescriptor,
                connectionId: $connectionId,
                participantId: $result->participant->id,
                sessionId: $result->sessionId,
                participantType: $result->participant->participantType,
                studentId: $result->participant->studentId,
            );

            unset($this->pendingConnectionIds[$fileDescriptor]);

            $this->pushAuthenticated(
                server: $server,
                fileDescriptor: $fileDescriptor,
                result: $result,
            );
            $this->pushReconnectState(
                server: $server,
                fileDescriptor: $fileDescriptor,
                result: $result,
            );

            if ($previousFileDescriptor !== null) {
                $this->replacePreviousConnection(
                    server: $server,
                    fileDescriptor: $previousFileDescriptor,
                );
            }
        } catch (InvalidArgumentException) {
            $this->failAuthentication(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'INVALID_AUTHENTICATION_MESSAGE',
                message: 'A valid participant authentication message is required.',
            );
        } catch (InvalidParticipantTokenException) {
            $this->failAuthentication(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'PARTICIPANT_AUTHENTICATION_FAILED',
                message: 'Participant authentication failed.',
            );
        } catch (ParticipantConnectionRejectedException) {
            $this->failAuthentication(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'PARTICIPANT_CONNECTION_REJECTED',
                message: 'Participant connection was rejected.',
            );
        } catch (GameSessionFinishedException) {
            $this->failAuthentication(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'GAME_SESSION_FINISHED',
                message: 'The game session has finished.',
            );
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            $this->failAuthentication(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'INTERNAL_ERROR',
                message: 'An unexpected server error occurred.',
            );
        }
    }

    private function handleAuthenticatedMessage(
        Server $server,
        Frame $frame,
    ): void {
        $fileDescriptor = $frame->fd;
        $connection = $this->connectionRegistry->findAuthenticated(
            $fileDescriptor,
        );

        if ($connection === null) {
            $this->pushError(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'PARTICIPANT_CONNECTION_REJECTED',
                message: 'Participant connection was rejected.',
            );
            $this->disconnect($server, $fileDescriptor);

            return;
        }

        try {
            $message = $this->readAuthenticatedMessage($frame->data);
        } catch (InvalidArgumentException) {
            $this->pushInvalidAnswerMessage($server, $fileDescriptor);

            return;
        }

        if ($message['type'] !== self::ANSWER_SUBMIT_MESSAGE_TYPE) {
            $this->pushError(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'UNSUPPORTED_MESSAGE',
                message: 'This WebSocket message type is not supported yet.',
            );

            return;
        }

        try {
            $dto = AnswerSubmitMessage::fromPayload($message['payload']);
        } catch (InvalidArgumentException) {
            $this->pushInvalidAnswerMessage($server, $fileDescriptor);

            return;
        }

        try {
            $result = $this->answerSubmissionService->submitAnswer(
                sessionId: $connection->sessionId,
                participantId: $connection->participantId,
                dto: $dto,
            );

            $this->pushAnswerAccepted(
                server: $server,
                fileDescriptor: $fileDescriptor,
                result: $result,
            );
        } catch (AnswerSubmissionNotAllowedException) {
            $this->pushError(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'ANSWER_SUBMISSION_NOT_ALLOWED',
                message: 'Answers can only be submitted while the game is active.',
            );
        } catch (AnswerDeadlineExpiredException) {
            $this->pushError(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'ANSWER_DEADLINE_EXPIRED',
                message: 'The answer deadline has expired.',
            );
        } catch (AnswerAlreadySubmittedException) {
            $this->pushError(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'ANSWER_ALREADY_SUBMITTED',
                message: 'An answer has already been submitted for this question.',
            );
        } catch (InvalidSelectedOptionsException) {
            $this->pushError(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'INVALID_SELECTED_OPTIONS',
                message: 'Selected options are invalid for the current question.',
            );
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
            $this->pushError(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'INTERNAL_ERROR',
                message: 'An unexpected server error occurred.',
            );
        }
    }

    public function close(Server $server, int $fileDescriptor): void
    {
        unset($this->pendingConnectionIds[$fileDescriptor]);

        $connection = $this->connectionRegistry->remove($fileDescriptor);

        if ($connection === null) {
            return;
        }

        try {
            $this->participantConnectionService->disconnect(
                sessionId: $connection->sessionId,
                participantId: $connection->participantId,
            );
        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
        }
    }

    private function closeTimedOutConnection(
        Server $server,
        int $fileDescriptor,
        string $connectionId,
    ): void {
        if (!$this->connectionRegistry->isPending($fileDescriptor, $connectionId)) {
            return;
        }

        unset($this->pendingConnectionIds[$fileDescriptor]);

        $this->pushError(
            server: $server,
            fileDescriptor: $fileDescriptor,
            code: 'AUTHENTICATION_TIMEOUT',
            message: 'Participant authentication timed out.',
        );

        $this->connectionRegistry->remove($fileDescriptor);
        $this->disconnect($server, $fileDescriptor);
    }

    private function readParticipantToken(string $message): string
    {
        try {
            $data = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'A valid participant authentication message is required.',
                0,
                $exception,
            );
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException(
                'A valid participant authentication message is required.',
            );
        }

        if (($data['type'] ?? null) !== self::AUTHENTICATE_MESSAGE_TYPE) {
            throw new InvalidArgumentException(
                'A valid participant authentication message is required.',
            );
        }

        $payload = $data['payload'] ?? null;

        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException(
                'A valid participant authentication message is required.',
            );
        }

        $participantToken = $payload['participantToken'] ?? null;

        if (!is_string($participantToken) || trim($participantToken) === '') {
            throw new InvalidArgumentException(
                'A valid participant authentication message is required.',
            );
        }

        return $participantToken;
    }

    /**
     * @return array{type: string, payload: array<string, mixed>}
     */
    private function readAuthenticatedMessage(string $message): array
    {
        try {
            $data = json_decode($message, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'A valid answer submission message is required.',
                0,
                $exception,
            );
        }

        if (!$data instanceof stdClass) {
            throw new InvalidArgumentException(
                'A valid answer submission message is required.',
            );
        }

        $type = $data->type ?? null;

        if (!is_string($type) || trim($type) === '') {
            throw new InvalidArgumentException(
                'A valid answer submission message is required.',
            );
        }

        $payload = $data->payload ?? null;

        if (!$payload instanceof stdClass) {
            throw new InvalidArgumentException(
                'A valid answer submission message is required.',
            );
        }

        return [
            'type' => $type,
            'payload' => (array) $payload,
        ];
    }

    private function pushAuthenticated(
        Server $server,
        int $fileDescriptor,
        ParticipantConnectionResultDTO $result,
    ): void {
        $this->push($server, $fileDescriptor, 'PARTICIPANT_AUTHENTICATED', [
            'participant' => $this->participantResponse(
                $result->participant,
            ),
            'session' => [
                'id' => $result->sessionId,
                'quiz' => [
                    'title' => $result->quizTitle,
                    'version' => $result->quizVersion,
                ],
                'status' => $result->sessionStatus->value,
                'currentQuestionOrder' => $result->currentQuestionOrder,
            ],
        ]);
    }

    private function pushAnswerAccepted(
        Server $server,
        int $fileDescriptor,
        AnswerSubmissionResultDTO $result,
    ): void {
        $this->push($server, $fileDescriptor, 'ANSWER_ACCEPTED', [
            'questionOrder' => $result->questionOrder,
            'responseTimeMs' => $result->responseTimeMs,
            'answeredAt' => $result->answeredAt->format(DATE_ATOM),
        ]);
    }

    private function pushInvalidAnswerMessage(
        Server $server,
        int $fileDescriptor,
    ): void {
        $this->pushError(
            server: $server,
            fileDescriptor: $fileDescriptor,
            code: 'INVALID_ANSWER_MESSAGE',
            message: 'A valid answer submission message is required.',
        );
    }

    private function pushReconnectState(
        Server $server,
        int $fileDescriptor,
        ParticipantConnectionResultDTO $result,
    ): void {
        if (
            $result->sessionStatus !== QuizSessionStatus::ACTIVE
            || $result->currentQuestion === null
            || $result->currentQuestionStartedAt === null
            || $result->currentQuestionDeadline === null
        ) {
            return;
        }

        $this->push(
            server: $server,
            fileDescriptor: $fileDescriptor,
            type: 'GAME_STARTED',
            payload: $this->payloadMapper->participantGameStarted($result),
        );
        $this->push(
            server: $server,
            fileDescriptor: $fileDescriptor,
            type: 'QUESTION_STARTED',
            payload: $this->payloadMapper->participantQuestionStarted($result),
        );
    }

    private function replacePreviousConnection(
        Server $server,
        int $fileDescriptor,
    ): void {
        $this->push($server, $fileDescriptor, 'CONNECTION_REPLACED', [
            'message' => 'This participant connected from another client.',
        ]);

        $this->disconnect($server, $fileDescriptor);
    }

    private function failAuthentication(
        Server $server,
        int $fileDescriptor,
        string $code,
        string $message,
    ): void {
        unset($this->pendingConnectionIds[$fileDescriptor]);
        $this->connectionRegistry->remove($fileDescriptor);
        $this->pushError(
            server: $server,
            fileDescriptor: $fileDescriptor,
            code: $code,
            message: $message,
        );
        $this->disconnect($server, $fileDescriptor);
    }

    private function pushError(
        Server $server,
        int $fileDescriptor,
        string $code,
        string $message,
    ): void {
        $this->push($server, $fileDescriptor, 'ERROR', [
            'code' => $code,
            'message' => $message,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function push(
        Server $server,
        int $fileDescriptor,
        string $type,
        array $payload,
    ): void {
        if (!$this->isEstablished($server, $fileDescriptor)) {
            return;
        }

        $server->push(
            $fileDescriptor,
            $this->messageEncoder->encode($type, $payload),
        );
    }

    private function disconnect(Server $server, int $fileDescriptor): void
    {
        if (!$this->isEstablished($server, $fileDescriptor)) {
            return;
        }

        $server->disconnect(
            $fileDescriptor,
            self::POLICY_VIOLATION_CLOSE_CODE,
        );
    }

    private function isEstablished(
        Server $server,
        int $fileDescriptor,
    ): bool {
        return $server->isEstablished($fileDescriptor);
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
}
