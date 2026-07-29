<?php

declare(strict_types=1);

namespace CodeLandQuiz\Game;

use CodeLandQuiz\DTO\ParticipantConnectionResultDTO;
use CodeLandQuiz\DTO\ParticipantTokenPayloadDTO;
use CodeLandQuiz\DTO\PublicSessionQuestionDTO;
use CodeLandQuiz\DTO\SessionParticipantItemDTO;
use CodeLandQuiz\Game\Exception\GameSessionFinishedException;
use CodeLandQuiz\Game\Exception\ParticipantConnectionRejectedException;
use CodeLandQuiz\Model\QuizSessionOverview;
use CodeLandQuiz\Model\QuizSessionStatus;
use CodeLandQuiz\Model\SessionParticipantOverview;
use CodeLandQuiz\QuizSession\PublicSessionQuestionMapper;
use CodeLandQuiz\Repository\QuizSessionRepository;
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
        private PublicSessionQuestionMapper $publicQuestionMapper,
        private TransactionManager $transactionManager,
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
                $session = $this->sessions->findOverviewByIdForUpdate(
                    $payload->sessionId,
                );

                if ($session === null) {
                    throw new ParticipantConnectionRejectedException(
                        'Participant connection was rejected.',
                    );
                }

                if ($session->status === QuizSessionStatus::FINISHED) {
                    throw new GameSessionFinishedException(
                        'The game session has finished.',
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
                $currentQuestion = $this->currentQuestion($session);

                return $this->connectionResult(
                    session: $session,
                    participant: $participant,
                    isConnected: true,
                    currentQuestion: $currentQuestion,
                );
            },
        );
    }

    public function disconnect(int $sessionId, int $participantId): void
    {
        $this->transactionManager->transactional(
            function () use ($sessionId, $participantId): void {
                $session = $this->sessions->findOverviewByIdForUpdate(
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
            currentQuestionStartedAt: $session->currentQuestionStartedAt,
            currentQuestionDeadline: $session->currentQuestionDeadline,
            questionCount: $session->questionCount,
        );
    }

    private function currentQuestion(
        QuizSessionOverview $session,
    ): ?PublicSessionQuestionDTO {
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

        return $this->publicQuestionMapper->map($question);
    }
}
