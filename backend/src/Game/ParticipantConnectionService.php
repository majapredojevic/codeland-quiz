<?php

declare(strict_types=1);

namespace CodeLandQuiz\Game;

use CodeLandQuiz\DTO\ClosedSessionQuestionStateDTO;
use CodeLandQuiz\DTO\FinalQuizSessionResultDTO;
use CodeLandQuiz\DTO\ParticipantConnectionResultDTO;
use CodeLandQuiz\DTO\ParticipantTokenPayloadDTO;
use CodeLandQuiz\DTO\PublicSessionQuestionDTO;
use CodeLandQuiz\DTO\QuizSessionItemDTO;
use CodeLandQuiz\DTO\SessionParticipantItemDTO;
use CodeLandQuiz\Game\Exception\ParticipantConnectionRejectedException;
use CodeLandQuiz\Model\QuizSessionOverview;
use CodeLandQuiz\Model\QuizSessionStatus;
use CodeLandQuiz\Model\SessionParticipantOverview;
use CodeLandQuiz\Model\SessionQuestionOverview;
use CodeLandQuiz\QuizSession\ClosedQuestionResultAssembler;
use CodeLandQuiz\QuizSession\FinalQuizSessionResultAssembler;
use CodeLandQuiz\QuizSession\PublicSessionQuestionMapper;
use CodeLandQuiz\Repository\QuizSessionRepository;
use CodeLandQuiz\Repository\ParticipantAnswerRepository;
use CodeLandQuiz\Repository\SessionQuestionRepository;
use CodeLandQuiz\Repository\SessionParticipantRepository;
use CodeLandQuiz\Support\TransactionManager;

final readonly class ParticipantConnectionService
{
    public function __construct(
        private ParticipantTokenVerifier $participantTokenVerifier,
        private QuizSessionRepository $sessions,
        private SessionParticipantRepository $participants,
        private SessionQuestionRepository $sessionQuestions,
        private ParticipantAnswerRepository $answers,
        private PublicSessionQuestionMapper $publicQuestionMapper,
        private TransactionManager $transactionManager,
        private ClosedQuestionResultAssembler $closedQuestionResultAssembler,
        private FinalQuizSessionResultAssembler $finalResultAssembler,
    ) {
    }

    public function authenticate(
        string $participantToken,
    ): ParticipantConnectionResultDTO {
        $payload = $this->participantTokenVerifier->verify(
            $participantToken,
        );

        return $this->transactionManager->transactional(
            function () use ($payload): ParticipantConnectionResultDTO {
                $session = $this->sessions->findOverviewByIdForShare(
                    $payload->sessionId,
                );

                if ($session === null) {
                    throw new ParticipantConnectionRejectedException(
                        'Participant connection was rejected.',
                    );
                }

                $participant =
                    $this->participants->findOverviewByIdForUpdate(
                        $payload->participantId,
                    );

                if ($participant === null || $participant->isRemoved) {
                    throw new ParticipantConnectionRejectedException(
                        'Participant connection was rejected.',
                    );
                }

                $this->ensureParticipantMatchesPayload(
                    participant: $participant,
                    payload: $payload,
                );

                $this->participants->markConnected($participant->id);
                $currentQuestion = null;
                $closedQuestion = null;
                $finalResult = null;
                $currentQuestionSelectedOptionIds = [];

                if ($session->status === QuizSessionStatus::FINISHED) {
                    $finalResult = $this->finalResultAssembler->assemble(
                        session: $this->sessionItem($session),
                        stateChanged: false,
                    );
                } else {
                    $question = $this->activeQuestion($session);

                    if ($question !== null) {
                        if ($session->currentQuestionClosedAt === null) {
                            $currentQuestion = $this->publicQuestionMapper->map(
                                $question,
                            );
                            $answer = $this->answers->findByParticipantAndQuestion(
                                participantId: $participant->id,
                                sessionQuestionId: $question->id,
                            );
                            $currentQuestionSelectedOptionIds =
                                $answer?->selectedOptionIds ?? [];
                        } else {
                            $closedQuestion =
                                $this->closedQuestionResultAssembler->assemble(
                                    question: $question,
                                    closedAt:
                                        $session->currentQuestionClosedAt,
                                );
                        }
                    }
                }

                return $this->connectionResult(
                    session: $session,
                    participant: $participant,
                    isConnected: true,
                    currentQuestion: $currentQuestion,
                    closedQuestion: $closedQuestion,
                    finalResult: $finalResult,
                    currentQuestionSelectedOptionIds:
                        $currentQuestionSelectedOptionIds,
                );
            },
        );
    }

    public function disconnect(int $sessionId, int $participantId): void
    {
        $this->transactionManager->transactional(
            function () use ($sessionId, $participantId): void {
                $session = $this->sessions->findOverviewByIdForShare(
                    $sessionId,
                );

                if ($session === null) {
                    return;
                }

                $participant =
                    $this->participants->findOverviewByIdForUpdate(
                        $participantId,
                    );

                if ($participant === null || $participant->isRemoved) {
                    return;
                }

                if ($participant->sessionId !== $sessionId) {
                    return;
                }

                $this->participants->markDisconnected($participant->id);
            },
        );
    }

    private function ensureParticipantMatchesPayload(
        SessionParticipantOverview $participant,
        ParticipantTokenPayloadDTO $payload,
    ): void {
        if (
            $participant->sessionId !== $payload->sessionId
            || $participant->participantType !== $payload->participantType
            || $participant->studentId !== $payload->studentId
        ) {
            throw new ParticipantConnectionRejectedException(
                'Participant connection was rejected.',
            );
        }
    }

    private function connectionResult(
        QuizSessionOverview $session,
        SessionParticipantOverview $participant,
        bool $isConnected,
        ?PublicSessionQuestionDTO $currentQuestion,
        ?ClosedSessionQuestionStateDTO $closedQuestion,
        ?FinalQuizSessionResultDTO $finalResult,
        array $currentQuestionSelectedOptionIds,
    ): ParticipantConnectionResultDTO {
        return new ParticipantConnectionResultDTO(
            participant: new SessionParticipantItemDTO(
                id: $participant->id,
                sessionId: $participant->sessionId,
                participantType: $participant->participantType,
                studentId: $participant->studentId,
                nickname: $participant->nickname,
                avatarKey: $participant->avatarKey,
                totalScore: $participant->totalScore,
                isConnected: $isConnected,
                isRemoved: $participant->isRemoved,
                joinedAt: $participant->joinedAt,
            ),
            sessionId: $session->id,
            quizTitle: $session->quizTitle,
            quizVersion: $session->quizVersion,
            sessionStatus: $session->status,
            currentQuestionOrder: $session->currentQuestionOrder,
            currentQuestion: $currentQuestion,
            closedQuestion: $closedQuestion,
            finalResult: $finalResult,
            currentQuestionSelectedOptionIds:
                $currentQuestionSelectedOptionIds,
            currentQuestionStartedAt: $session->currentQuestionStartedAt,
            currentQuestionDeadline: $session->currentQuestionDeadline,
            questionCount: $session->questionCount,
        );
    }

    private function activeQuestion(
        QuizSessionOverview $session,
    ): ?SessionQuestionOverview {
        if ($session->status === QuizSessionStatus::WAITING) {
            return null;
        }

        if (
            $session->currentQuestionOrder === null
            || $session->currentQuestionStartedAt === null
            || $session->currentQuestionDeadline === null
        ) {
            throw new ParticipantConnectionRejectedException(
                'Participant connection was rejected.',
            );
        }

        $question = $this->sessionQuestions->findBySessionAndOrder(
            sessionId: $session->id,
            questionOrder: $session->currentQuestionOrder,
        );

        if ($question === null) {
            throw new ParticipantConnectionRejectedException(
                'Participant connection was rejected.',
            );
        }

        return $question;
    }

    private function sessionItem(
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
