<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use CodeLandQuiz\DTO\AnswerSubmissionResultDTO;
use CodeLandQuiz\DTO\ParticipantConnectionResultDTO;
use CodeLandQuiz\DTO\SessionParticipantItemDTO;
use CodeLandQuiz\Game\AnswerSubmissionService;
use CodeLandQuiz\Game\Exception\AnswerAlreadySubmittedException;
use CodeLandQuiz\Game\Exception\AnswerDeadlineExpiredException;
use CodeLandQuiz\Game\Exception\AnswerQuestionClosedException;
use CodeLandQuiz\Game\Exception\AnswerSubmissionNotAllowedException;
use CodeLandQuiz\Game\Exception\InvalidParticipantTokenException;
use CodeLandQuiz\Game\Exception\InvalidSelectedOptionsException;
use CodeLandQuiz\Game\Exception\ParticipantConnectionRejectedException;
use CodeLandQuiz\Game\ParticipantConnectionService;
use CodeLandQuiz\Model\QuizSessionStatus;
use CodeLandQuiz\Observability\RuntimeLogger;
use CodeLandQuiz\Observability\PerformanceProfiler;
use CodeLandQuiz\WebSocket\Exception\WebSocketRateLimitExceededException;
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
    private const HEARTBEAT_MESSAGE_TYPE = 'HEARTBEAT';
    private const HEARTBEAT_ACK_MESSAGE_TYPE = 'HEARTBEAT_ACK';
    private const STALE_CONNECTION_CLOSE_CODE = 1001;
    private const NANOSECONDS_PER_SECOND = 1_000_000_000;

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
        private readonly WebSocketConnectionLimiter $connectionLimiter,
        private readonly WebSocketAbuseLimiter $abuseLimiter,
        private readonly RuntimeLogger $logger,
        private readonly ?PerformanceProfiler $profiler = null,
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
                    $this->logger->error(
                        'websocket.authentication_timeout_failed',
                        [
                            'fd' => $fileDescriptor,
                            'connectionId' => $connectionId,
                            'exception' => $throwable::class,
                        ],
                    );
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

        $authenticationStartedAt = $this->profiler?->start();

        try {
            $this->abuseLimiter->recordAuthenticationAttempt(
                $fileDescriptor,
            );
            $participantToken = $this->profile(
                'ws_auth.message_parse',
                fn (): string => $this->readParticipantToken($frame->data),
            );
            $result = $this->participantConnectionService->authenticate(
                $participantToken,
            );
            $previousFileDescriptor = $this->profile(
                'ws_auth.registry_update',
                function () use (
                    $fileDescriptor,
                    $connectionId,
                    $result,
                ): ?int {
                    $previousFileDescriptor = $this->connectionRegistry
                        ->authenticate(
                            fileDescriptor: $fileDescriptor,
                            connectionId: $connectionId,
                            participantId: $result->participant->id,
                            sessionId: $result->sessionId,
                            participantType:
                                $result->participant->participantType,
                            studentId: $result->participant->studentId,
                            participantTokenExpiresAt:
                                $result->participantTokenExpiresAt,
                        );

                    unset($this->pendingConnectionIds[$fileDescriptor]);
                    $this->connectionLimiter->markAuthenticated(
                        $fileDescriptor,
                    );
                    $this->abuseLimiter->markAuthenticated($fileDescriptor);

                    if ($result->currentQuestionSelectedOptionIds !== []) {
                        $questionOrder = $result->currentQuestion
                            ?->questionOrder
                            ?? $result->currentQuestionOrder;

                        if ($questionOrder !== null) {
                            $this->connectionRegistry->markAnswerAccepted(
                                $fileDescriptor,
                                $questionOrder,
                            );
                        }
                    }

                    return $previousFileDescriptor;
                },
            );

            $this->profile(
                'ws_auth.authentication_response_send',
                function () use ($server, $fileDescriptor, $result): void {
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
                },
            );

            if ($previousFileDescriptor !== null) {
                $this->profile(
                    'ws_auth.connection_replacement',
                    fn () => $this->replacePreviousConnection(
                        server: $server,
                        fileDescriptor: $previousFileDescriptor,
                    ),
                );
            }
        } catch (WebSocketRateLimitExceededException) {
            $this->failAuthentication(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'AUTHENTICATION_RATE_LIMITED',
                message: 'Previše pokušaja povezivanja.',
            );
        } catch (InvalidArgumentException) {
            $this->rejectAuthenticationAttempt(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'INVALID_AUTHENTICATION_MESSAGE',
                message: 'A valid participant authentication message is required.',
            );
        } catch (InvalidParticipantTokenException) {
            $this->rejectAuthenticationAttempt(
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
        } catch (Throwable $throwable) {
            $this->logger->error('websocket.authentication_failed', [
                'fd' => $fileDescriptor,
                'connectionId' => $connectionId,
                'exception' => $throwable::class,
            ]);
            $this->failAuthentication(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'INTERNAL_ERROR',
                message: 'An unexpected server error occurred.',
            );
        } finally {
            if ($authenticationStartedAt !== null) {
                $this->profiler?->recordDuration(
                    'ws_auth.gateway_total',
                    $authenticationStartedAt,
                );
            }
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

        if ($connection->tokenHasExpired(time())) {
            $this->pushError(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'PARTICIPANT_SESSION_EXPIRED',
                message: 'Ova sesija je istekla. Pridruži se igri ponovo.',
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

        $this->connectionRegistry->touchAuthenticated(
            $fileDescriptor,
            $connection->connectionId,
        );

        if (
            $message['type'] === self::HEARTBEAT_ACK_MESSAGE_TYPE
            && $message['payload'] === []
        ) {
            return;
        }

        try {
            $this->abuseLimiter->recordAnswerAttempt($fileDescriptor);
        } catch (WebSocketRateLimitExceededException) {
            $this->pushError(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'ANSWER_RATE_LIMITED',
                message: 'Previše pokušaja slanja odgovora.',
            );
            $this->disconnect($server, $fileDescriptor);

            return;
        }

        if ($this->connectionRegistry->hasAcceptedCurrentAnswer($fileDescriptor)) {
            $this->pushError(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'ANSWER_ALREADY_SUBMITTED',
                message: 'Odgovor je već poslan.',
            );

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

        $answerStartedAt = $this->profiler?->start();

        try {
            $result = $this->answerSubmissionService->submitAnswer(
                sessionId: $connection->sessionId,
                participantId: $connection->participantId,
                dto: $dto,
            );

            $this->profile(
                'answer.registry_update',
                fn () => $this->connectionRegistry->markAnswerAccepted(
                    $fileDescriptor,
                    $result->questionOrder,
                ),
            );

            $this->profile(
                'answer.accepted_serialization_send',
                fn () => $this->pushAnswerAccepted(
                    server: $server,
                    fileDescriptor: $fileDescriptor,
                    result: $result,
                ),
            );
        } catch (AnswerSubmissionNotAllowedException) {
            $this->pushError(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'ANSWER_SUBMISSION_NOT_ALLOWED',
                message: 'Answers can only be submitted while the game is active.',
            );
        } catch (AnswerQuestionClosedException) {
            $this->pushError(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'ANSWER_QUESTION_CLOSED',
                message: 'The current question is closed.',
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
            $this->logger->error('websocket.answer_submission_failed', [
                'fd' => $fileDescriptor,
                'connectionId' => $connection->connectionId,
                'sessionId' => $connection->sessionId,
                'participantId' => $connection->participantId,
                'exception' => $throwable::class,
            ]);
            $this->pushError(
                server: $server,
                fileDescriptor: $fileDescriptor,
                code: 'INTERNAL_ERROR',
                message: 'An unexpected server error occurred.',
            );
        } finally {
            if ($answerStartedAt !== null) {
                $this->profiler?->recordDuration(
                    'answer.gateway_total',
                    $answerStartedAt,
                );
            }
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
                shouldMarkDisconnected: fn (): bool =>
                    $this->connectionRegistry
                        ->findCurrentFileDescriptorByParticipantId(
                            $connection->participantId,
                        ) === null,
            );
        } catch (Throwable $throwable) {
            $this->logger->error('websocket.presence_disconnect_failed', [
                'fd' => $fileDescriptor,
                'connectionId' => $connection->connectionId,
                'sessionId' => $connection->sessionId,
                'participantId' => $connection->participantId,
                'exception' => $throwable::class,
            ]);
        }
    }

    public function heartbeatSweep(
        Server $server,
        int $monotonicNanoseconds,
        int $staleTimeoutSeconds,
    ): int {
        $staleThresholdNanoseconds = $staleTimeoutSeconds
            * self::NANOSECONDS_PER_SECOND;
        $staleConnectionsClosed = 0;

        foreach ($this->connectionRegistry->authenticatedConnections() as $connection) {
            if (!$this->connectionRegistry->isCurrent(
                $connection->fileDescriptor,
                $connection->connectionId,
            )) {
                continue;
            }

            $idleNanoseconds = $connection->idleNanoseconds(
                $monotonicNanoseconds,
            );

            if ($idleNanoseconds < $staleThresholdNanoseconds) {
                $this->push(
                    server: $server,
                    fileDescriptor: $connection->fileDescriptor,
                    type: self::HEARTBEAT_MESSAGE_TYPE,
                    payload: ['acknowledge' => true],
                );

                continue;
            }

            $removedConnection = $this->connectionRegistry->removeIfCurrent(
                $connection->fileDescriptor,
                $connection->connectionId,
            );

            if ($removedConnection === null) {
                continue;
            }

            $this->connectionLimiter->remove($connection->fileDescriptor);
            $this->abuseLimiter->removeConnection($connection->fileDescriptor);
            $staleConnectionsClosed++;
            $this->logger->warning('websocket.stale_connection_closed', [
                'fd' => $connection->fileDescriptor,
                'connectionId' => $connection->connectionId,
                'sessionId' => $connection->sessionId,
                'participantId' => $connection->participantId,
                'idleMs' => round($idleNanoseconds / 1_000_000, 3),
                'staleAfterSeconds' => $staleTimeoutSeconds,
            ]);

            if ($this->isEstablished($server, $connection->fileDescriptor)) {
                $server->disconnect(
                    $connection->fileDescriptor,
                    self::STALE_CONNECTION_CLOSE_CODE,
                    'Heartbeat timeout',
                );
            }

            try {
                $this->participantConnectionService->disconnect(
                    sessionId: $connection->sessionId,
                    participantId: $connection->participantId,
                    shouldMarkDisconnected: fn (): bool =>
                        $this->connectionRegistry
                            ->findCurrentFileDescriptorByParticipantId(
                                $connection->participantId,
                            ) === null,
                );
            } catch (Throwable $throwable) {
                $this->logger->error(
                    'websocket.stale_presence_disconnect_failed',
                    [
                        'fd' => $connection->fileDescriptor,
                        'connectionId' => $connection->connectionId,
                        'sessionId' => $connection->sessionId,
                        'participantId' => $connection->participantId,
                        'exception' => $throwable::class,
                    ],
                );
            }
        }

        return $staleConnectionsClosed;
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
            $result->sessionStatus === QuizSessionStatus::FINISHED
            && $result->finalResult !== null
        ) {
            $this->push(
                server: $server,
                fileDescriptor: $fileDescriptor,
                type: 'GAME_FINISHED',
                payload: $this->payloadMapper->gameFinished(
                    $result->finalResult,
                ),
            );

            foreach ($result->finalResult->leaderboard as $entry) {
                if ($entry->participantId !== $result->participant->id) {
                    continue;
                }

                $this->push(
                    server: $server,
                    fileDescriptor: $fileDescriptor,
                    type: 'FINAL_RESULT',
                    payload: $this->payloadMapper->finalParticipantResult(
                        $entry,
                    ),
                );

                break;
            }

            return;
        }

        if (
            $result->sessionStatus !== QuizSessionStatus::ACTIVE
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

        if ($result->currentQuestion !== null) {
            $this->push(
                server: $server,
                fileDescriptor: $fileDescriptor,
                type: 'QUESTION_STARTED',
                payload: $this->payloadMapper->participantQuestionStarted(
                    $result,
                ),
            );

            return;
        }

        if ($result->closedQuestion === null) {
            return;
        }

        $this->push(
            server: $server,
            fileDescriptor: $fileDescriptor,
            type: 'QUESTION_CLOSED',
            payload: $this->payloadMapper->participantQuestionClosed($result),
        );

        foreach ($result->closedQuestion->participantResults as $participantResult) {
            if (
                $participantResult->participantId
                !== $result->participant->id
            ) {
                continue;
            }

            $this->push(
                server: $server,
                fileDescriptor: $fileDescriptor,
                type: 'ANSWER_RESULT',
                payload: $this->payloadMapper->answerResult(
                    result: $participantResult,
                    questionOrder:
                        $result->closedQuestion->question->questionOrder,
                ),
            );

            break;
        }

        $this->push(
            server: $server,
            fileDescriptor: $fileDescriptor,
            type: 'LEADERBOARD_UPDATED',
            payload: $this->payloadMapper->leaderboardUpdated(
                $result->closedQuestion,
            ),
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

    private function rejectAuthenticationAttempt(
        Server $server,
        int $fileDescriptor,
        string $code,
        string $message,
    ): void {
        $this->pushError(
            server: $server,
            fileDescriptor: $fileDescriptor,
            code: $code,
            message: $message,
        );
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
            'nickname' => $participant->nickname,
            'avatarKey' => $participant->avatarKey,
            'totalScore' => $participant->totalScore,
            'isConnected' => $participant->isConnected,
            'joinedAt' => $participant->joinedAt->format(DATE_ATOM),
        ];
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function profile(string $name, callable $operation): mixed
    {
        return $this->profiler === null
            ? $operation()
            : $this->profiler->measure($name, $operation);
    }
}
