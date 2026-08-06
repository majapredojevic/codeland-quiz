<?php

declare(strict_types=1);

namespace CodeLandQuiz\QuizSession;

use CodeLandQuiz\Auth\AuditLogService;
use CodeLandQuiz\DTO\CloseSessionQuestionResultDTO;
use CodeLandQuiz\DTO\PublicSessionQuestionDTO;
use CodeLandQuiz\DTO\QuizSessionItemDTO;
use CodeLandQuiz\DTO\StartQuizSessionResultDTO;
use CodeLandQuiz\Model\AuditAction;
use CodeLandQuiz\Model\QuestionOverview;
use CodeLandQuiz\Model\QuizSessionOverview;
use CodeLandQuiz\Model\QuizSessionStatus;
use CodeLandQuiz\Model\SessionQuestionOverview;
use CodeLandQuiz\Question\QuestionContentValidator;
use CodeLandQuiz\Quiz\Exception\QuizNotFoundException;
use CodeLandQuiz\QuizSession\Exception\GamePinAlreadyExistsException;
use CodeLandQuiz\QuizSession\Exception\GamePinGenerationFailedException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionCannotStartException;
use CodeLandQuiz\QuizSession\Exception\QuizInactiveException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionNotFoundException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionQuestionCannotCloseException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionQuestionStateConflictException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionStateConflictException;
use CodeLandQuiz\Repository\QuestionRepository;
use CodeLandQuiz\Repository\QuizRepository;
use CodeLandQuiz\Repository\QuizSessionRepository;
use CodeLandQuiz\Repository\QuizSessionResultRepository;
use CodeLandQuiz\Repository\SessionQuestionRepository;
use CodeLandQuiz\Support\TransactionManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class QuizSessionService
{
    private const AUDIT_ENTITY_TYPE = 'QUIZ_SESSION';
    private const MAX_PIN_GENERATION_ATTEMPTS = 10;

    public function __construct(
        private QuizRepository $quizzes,
        private QuestionRepository $questions,
        private QuizSessionRepository $sessions,
        private SessionQuestionRepository $sessionQuestions,
        private PublicSessionQuestionMapper $publicQuestionMapper,
        private QuestionContentValidator $questionContentValidator,
        private GamePinGenerator $gamePinGenerator,
        private AuditLogService $auditLogService,
        private TransactionManager $transactionManager,
        private QuizSessionResultRepository $sessionResults,
        private ClosedQuestionResultAssembler $closedQuestionResultAssembler,
    ) {
    }

    public function createSession(
        int $actorUserId,
        int $quizId,
    ): QuizSessionItemDTO {
        for ($attempt = 1; $attempt <= self::MAX_PIN_GENERATION_ATTEMPTS; $attempt++) {
            $gamePin = $this->gamePinGenerator->generate();

            try {
                $sessionId = $this->transactionManager->transactional(
                    fn (): int => $this->createSessionWithPin(
                        $actorUserId,
                        $quizId,
                        $gamePin,
                    ),
                );
            } catch (GamePinAlreadyExistsException) {
                continue;
            }

            $session = $this->sessions->findOverviewById($sessionId);

            if ($session === null) {
                throw new RuntimeException('Created quiz session was not found.');
            }

            return $this->toItem($session);
        }

        throw new GamePinGenerationFailedException(
            'A unique game PIN could not be generated.',
        );
    }

    public function getSession(
        int $sessionId,
    ): QuizSessionItemDTO {
        $session = $this->sessions->findOverviewById($sessionId);

        if ($session === null) {
            throw new QuizSessionNotFoundException(
                'Quiz session was not found.',
            );
        }

        return $this->toItem($session);
    }

    public function startSession(
        int $actorUserId,
        int $sessionId,
    ): StartQuizSessionResultDTO {
        return $this->transactionManager->transactional(
            function () use ($actorUserId, $sessionId): StartQuizSessionResultDTO {
                $session = $this->sessions->findOverviewByIdForUpdate(
                    $sessionId,
                );

                if ($session === null) {
                    throw new QuizSessionNotFoundException(
                        'Quiz session was not found.',
                    );
                }

                if ($session->status === QuizSessionStatus::FINISHED) {
                    throw new QuizSessionStateConflictException(
                        'A finished quiz session cannot be started.',
                    );
                }

                if ($session->status === QuizSessionStatus::ACTIVE) {
                    return $this->activeSessionResult($session);
                }

                if ($session->participantCount < 1) {
                    throw new QuizSessionCannotStartException(
                        'Quiz session must contain at least one participant before it can be started.',
                    );
                }

                $question = $this->sessionQuestions->findBySessionAndOrder(
                    sessionId: $sessionId,
                    questionOrder: 1,
                );

                if ($question === null) {
                    throw new QuizSessionCannotStartException(
                        'Quiz session does not contain a question that can be started.',
                    );
                }

                $this->ensureFirstQuestionIsStartable($question);

                $this->sessions->markStarted(
                    sessionId: $sessionId,
                    questionOrder: 1,
                    timeLimitSeconds: $question->timeLimitSeconds,
                );

                $updatedSession = $this->sessions->findOverviewByIdForUpdate(
                    $sessionId,
                );

                if (!$this->wasSessionStarted($updatedSession)) {
                    throw new RuntimeException(
                        'Started quiz session state could not be verified.',
                    );
                }

                $this->auditLogService->log(
                    action: AuditAction::QUIZ_SESSION_STARTED,
                    userId: $actorUserId,
                    entityType: self::AUDIT_ENTITY_TYPE,
                    entityId: $sessionId,
                    metadata: [
                        'quizId' => $updatedSession->quizId,
                        'questionCount' => $updatedSession->questionCount,
                        'firstQuestionOrder' => 1,
                        'participantCount' => $updatedSession->participantCount,
                    ],
                );

                return new StartQuizSessionResultDTO(
                    session: $this->toItem($updatedSession),
                    currentQuestion: $this->toPublicQuestion($question),
                    questionCount: $updatedSession->questionCount,
                    stateChanged: true,
                );
            },
        );
    }

    public function closeCurrentQuestion(
        int $actorUserId,
        int $sessionId,
    ): CloseSessionQuestionResultDTO {
        return $this->transactionManager->transactional(
            function () use ($actorUserId, $sessionId): CloseSessionQuestionResultDTO {
                $session = $this->sessions->findOverviewByIdForUpdate(
                    $sessionId,
                );

                if ($session === null) {
                    throw new QuizSessionNotFoundException(
                        'Quiz session was not found.',
                    );
                }

                if ($session->status !== QuizSessionStatus::ACTIVE) {
                    throw new QuizSessionQuestionStateConflictException(
                        'Only an active quiz session can close a question.',
                    );
                }

                if (
                    $session->currentQuestionOrder === null
                    || $session->currentQuestionStartedAt === null
                    || $session->currentQuestionDeadline === null
                ) {
                    throw new QuizSessionQuestionCannotCloseException(
                        'Quiz session does not have a current question that can be closed.',
                    );
                }

                $question = $this->sessionQuestions->findBySessionAndOrder(
                    sessionId: $sessionId,
                    questionOrder: $session->currentQuestionOrder,
                );

                if ($question === null) {
                    throw new QuizSessionQuestionCannotCloseException(
                        'Quiz session does not have a current question that can be closed.',
                    );
                }

                if ($session->currentQuestionClosedAt !== null) {
                    return new CloseSessionQuestionResultDTO(
                        session: $this->toItem($session),
                        closedQuestion: $this->closedQuestionResultAssembler
                            ->assemble(
                                question: $question,
                                closedAt: $session->currentQuestionClosedAt,
                            ),
                        stateChanged: false,
                    );
                }

                $this->sessions->markCurrentQuestionClosed($sessionId);
                $this->sessionResults->recalculateParticipantTotalScores(
                    $sessionId,
                );

                $updatedSession = $this->sessions->findOverviewByIdForUpdate(
                    $sessionId,
                );

                if (
                    $updatedSession === null
                    || $updatedSession->currentQuestionClosedAt === null
                ) {
                    throw new RuntimeException(
                        'Closed quiz session question state could not be verified.',
                    );
                }

                $closedQuestion = $this->closedQuestionResultAssembler
                    ->assemble(
                        question: $question,
                        closedAt: $updatedSession->currentQuestionClosedAt,
                    );

                $this->auditLogService->log(
                    action: AuditAction::QUIZ_SESSION_QUESTION_CLOSED,
                    userId: $actorUserId,
                    entityType: self::AUDIT_ENTITY_TYPE,
                    entityId: $sessionId,
                    metadata: [
                        'quizId' => $updatedSession->quizId,
                        'questionOrder' => $question->questionOrder,
                        'participantCount' =>
                            $closedQuestion->stats->participantCount,
                        'answerCount' => $closedQuestion->stats->answerCount,
                        'correctAnswerCount' =>
                            $closedQuestion->stats->correctAnswerCount,
                        'closedBeforeDeadline' =>
                            $updatedSession->currentQuestionClosedAt
                                < $session->currentQuestionDeadline,
                    ],
                );

                return new CloseSessionQuestionResultDTO(
                    session: $this->toItem($updatedSession),
                    closedQuestion: $closedQuestion,
                    stateChanged: true,
                );
            },
        );
    }

    private function createSessionWithPin(
        int $actorUserId,
        int $quizId,
        string $gamePin,
    ): int {
        $quiz = $this->quizzes->findByIdForUpdate($quizId);

        if ($quiz === null) {
            throw new QuizNotFoundException('Quiz was not found.');
        }

        if (!$quiz->isActive) {
            throw new QuizInactiveException(
                'Only an active quiz can be used to create a session.',
            );
        }

        $questions = $this->questions->findAllByQuizId($quizId);

        if ($questions === []) {
            throw new QuizInactiveException(
                'Only a valid active quiz can be used to create a session.',
            );
        }

        foreach ($questions as $question) {
            $this->validateSnapshotQuestion($question);
        }

        $sessionId = $this->sessions->create(
            quizId: $quizId,
            hostUserId: $actorUserId,
            quizTitle: $quiz->title,
            quizVersion: $quiz->version,
            gamePin: $gamePin,
        );

        foreach ($questions as $question) {
            $sessionQuestionId = $this->sessions->createSnapshotQuestion(
                sessionId: $sessionId,
                sourceQuestionId: $question->id,
                questionText: $question->questionText,
                questionType: $question->questionType,
                imagePath: $question->imagePath,
                timeLimitSeconds: $question->timeLimitSeconds,
                maxPoints: $question->maxPoints,
                questionOrder: $question->questionOrder,
            );

            foreach ($question->options as $option) {
                $this->sessions->createSnapshotOption(
                    sessionQuestionId: $sessionQuestionId,
                    sourceOptionId: $option->id,
                    optionText: $option->optionText,
                    isCorrect: $option->isCorrect,
                    optionOrder: $option->optionOrder,
                );
            }
        }

        $this->auditLogService->log(
            action: AuditAction::QUIZ_SESSION_CREATED,
            userId: $actorUserId,
            entityType: self::AUDIT_ENTITY_TYPE,
            entityId: $sessionId,
            metadata: [
                'quizId' => $quizId,
                'quizTitle' => $quiz->title,
                'quizVersion' => $quiz->version,
                'gamePin' => $gamePin,
                'questionCount' => count($questions),
                'status' => QuizSessionStatus::WAITING->value,
            ],
        );

        return $sessionId;
    }

    private function validateSnapshotQuestion(QuestionOverview $question): void
    {
        try {
            $this->questionContentValidator->validateStoredQuestion($question);
        } catch (InvalidArgumentException $exception) {
            throw new QuizInactiveException(
                sprintf(
                    'Quiz question %d is invalid: %s',
                    $question->id,
                    $exception->getMessage(),
                ),
                0,
                $exception,
            );
        }
    }

    private function activeSessionResult(
        QuizSessionOverview $session,
    ): StartQuizSessionResultDTO {
        if ($session->currentQuestionOrder === null) {
            throw new RuntimeException(
                'Active quiz session current question is not set.',
            );
        }

        $question = $this->sessionQuestions->findBySessionAndOrder(
            sessionId: $session->id,
            questionOrder: $session->currentQuestionOrder,
        );

        if ($question === null) {
            throw new RuntimeException(
                'Active quiz session current question was not found.',
            );
        }

        return new StartQuizSessionResultDTO(
            session: $this->toItem($session),
            currentQuestion: $this->toPublicQuestion($question),
            questionCount: $session->questionCount,
            stateChanged: false,
        );
    }

    private function ensureFirstQuestionIsStartable(
        SessionQuestionOverview $question,
    ): void {
        if (
            $question->timeLimitSeconds < 30
            || $question->timeLimitSeconds > 300
            || $question->options === []
        ) {
            throw new QuizSessionCannotStartException(
                'Quiz session contains an invalid first question.',
            );
        }
    }

    private function wasSessionStarted(
        ?QuizSessionOverview $session,
    ): bool {
        return $session !== null
            && $session->status === QuizSessionStatus::ACTIVE
            && $session->currentQuestionOrder === 1
            && $session->currentQuestionStartedAt !== null
            && $session->currentQuestionDeadline !== null;
    }

    private function toPublicQuestion(
        SessionQuestionOverview $question,
    ): PublicSessionQuestionDTO {
        return $this->publicQuestionMapper->map($question);
    }

    private function toItem(
        QuizSessionOverview $session,
    ): QuizSessionItemDTO {
        return new QuizSessionItemDTO(
            id: $session->id,
            quizId: $session->quizId,
            hostUserId: $session->hostUserId,
            hostUserName: $session->hostUserName,
            quizTitle: $session->quizTitle,
            quizVersion: $session->quizVersion,
            gamePin: $session->gamePin,
            status: $session->status,
            currentQuestionOrder: $session->currentQuestionOrder,
            currentQuestionStartedAt: $session->currentQuestionStartedAt,
            currentQuestionDeadline: $session->currentQuestionDeadline,
            currentQuestionClosedAt: $session->currentQuestionClosedAt,
            joinDeadline: $session->joinDeadline,
            startedAt: $session->startedAt,
            endedAt: $session->endedAt,
            createdAt: $session->createdAt,
            questionCount: $session->questionCount,
            participantCount: $session->participantCount,
        );
    }
}
