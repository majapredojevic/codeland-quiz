<?php

declare(strict_types=1);

namespace CodeLandQuiz\QuizSession;

use CodeLandQuiz\Auth\AuditLogService;
use CodeLandQuiz\DTO\QuizSessionItemDTO;
use CodeLandQuiz\Model\AuditAction;
use CodeLandQuiz\Model\QuestionOverview;
use CodeLandQuiz\Model\QuizSessionOverview;
use CodeLandQuiz\Model\QuizSessionStatus;
use CodeLandQuiz\Question\QuestionContentValidator;
use CodeLandQuiz\Quiz\Exception\QuizNotFoundException;
use CodeLandQuiz\QuizSession\Exception\GamePinAlreadyExistsException;
use CodeLandQuiz\QuizSession\Exception\GamePinGenerationFailedException;
use CodeLandQuiz\QuizSession\Exception\QuizInactiveException;
use CodeLandQuiz\QuizSession\Exception\QuizSessionNotFoundException;
use CodeLandQuiz\Repository\QuestionRepository;
use CodeLandQuiz\Repository\QuizRepository;
use CodeLandQuiz\Repository\QuizSessionRepository;
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
        private QuestionContentValidator $questionContentValidator,
        private GamePinGenerator $gamePinGenerator,
        private AuditLogService $auditLogService,
        private TransactionManager $transactionManager,
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
            joinDeadline: $session->joinDeadline,
            startedAt: $session->startedAt,
            endedAt: $session->endedAt,
            createdAt: $session->createdAt,
            questionCount: $session->questionCount,
            participantCount: $session->participantCount,
        );
    }
}
