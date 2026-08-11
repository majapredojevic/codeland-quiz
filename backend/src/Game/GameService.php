<?php

declare(strict_types=1);

namespace CodeLandQuiz\Game;

use CodeLandQuiz\DTO\GameSessionPreviewDTO;
use CodeLandQuiz\DTO\JoinGameDTO;
use CodeLandQuiz\DTO\JoinGameResultDTO;
use CodeLandQuiz\DTO\SessionParticipantItemDTO;
use CodeLandQuiz\Game\Exception\ActiveStudentNotFoundException;
use CodeLandQuiz\Game\Exception\GameJoinClosedException;
use CodeLandQuiz\Game\Exception\GameSessionNotFoundException;
use CodeLandQuiz\Game\Exception\ParticipantAlreadyJoinedException;
use CodeLandQuiz\Game\Exception\ParticipantNicknameAlreadyExistsException;
use CodeLandQuiz\Model\ParticipantType;
use CodeLandQuiz\Model\QuizSessionOverview;
use CodeLandQuiz\Model\QuizSessionStatus;
use CodeLandQuiz\Model\SessionParticipantOverview;
use CodeLandQuiz\Model\StudentOverview;
use CodeLandQuiz\Repository\QuizSessionRepository;
use CodeLandQuiz\Repository\SessionParticipantRepository;
use CodeLandQuiz\Repository\StudentRepository;
use CodeLandQuiz\Support\TransactionManager;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final readonly class GameService
{
    public function __construct(
        private QuizSessionRepository $sessions,
        private StudentRepository $students,
        private SessionParticipantRepository $participants,
        private AvatarCatalog $avatarCatalog,
        private ParticipantTokenIssuer $participantTokenIssuer,
        private TransactionManager $transactionManager,
    ) {
    }

    public function getSessionPreview(
        string $gamePin,
    ): GameSessionPreviewDTO {
        $session = $this->sessions->findOverviewByActiveGamePin($gamePin);

        if ($session === null) {
            throw new GameSessionNotFoundException(
                'Game session was not found.',
            );
        }

        return new GameSessionPreviewDTO(
            quizTitle: $session->quizTitle,
            quizVersion: $session->quizVersion,
            status: $session->status,
            participantCount: $session->participantCount,
            canJoin: $this->canJoin($session),
            joinDeadline: $session->joinDeadline,
        );
    }

    public function joinGame(
        JoinGameDTO $dto,
    ): JoinGameResultDTO {
        return $this->transactionManager->transactional(
            function () use ($dto): JoinGameResultDTO {
                $session = $this->sessions
                    ->findOverviewByActiveGamePinForShare($dto->gamePin);

                if ($session === null) {
                    throw new GameSessionNotFoundException(
                        'Game session was not found.',
                    );
                }

                $this->ensureSessionCanBeJoined($session);
                $this->ensureAvatarIsValid($dto->avatarKey);

                $studentId = $this->resolveStudentId($dto, $session->id);
                $this->ensureNicknameIsAvailable($session->id, $dto->nickname);

                $participantId = $this->participants->create(
                    sessionId: $session->id,
                    participantType: $dto->participantType,
                    studentId: $studentId,
                    nickname: $dto->nickname,
                    avatarKey: $dto->avatarKey,
                );
                $participant = $this->participants->findOverviewById(
                    $participantId,
                );

                if ($participant === null) {
                    throw new RuntimeException(
                        'Created session participant was not found.',
                    );
                }

                return new JoinGameResultDTO(
                    participant: $this->toParticipantItem($participant),
                    sessionId: $session->id,
                    quizTitle: $session->quizTitle,
                    quizVersion: $session->quizVersion,
                    gamePin: $session->gamePin,
                    status: $session->status,
                    participantToken: $this->participantTokenIssuer->issue(
                        $participant,
                    ),
                );
            },
        );
    }

    private function canJoin(QuizSessionOverview $session): bool
    {
        return $session->status === QuizSessionStatus::WAITING
            && $this->joinDeadlineIsOpen($session->joinDeadline);
    }

    private function ensureSessionCanBeJoined(
        QuizSessionOverview $session,
    ): void {
        if ($session->status !== QuizSessionStatus::WAITING) {
            throw new GameJoinClosedException(
                'The game has already started.',
            );
        }

        if (!$this->joinDeadlineIsOpen($session->joinDeadline)) {
            throw new GameJoinClosedException(
                'Joining this game is closed.',
            );
        }
    }

    private function joinDeadlineIsOpen(?DateTimeImmutable $joinDeadline): bool
    {
        return $joinDeadline === null || $joinDeadline > new DateTimeImmutable();
    }

    private function ensureAvatarIsValid(string $avatarKey): void
    {
        if (!$this->avatarCatalog->contains($avatarKey)) {
            throw new InvalidArgumentException(
                'Selected participant avatar is invalid.',
            );
        }
    }

    private function resolveStudentId(
        JoinGameDTO $dto,
        int $sessionId,
    ): ?int {
        if ($dto->participantType === ParticipantType::GUEST) {
            return null;
        }

        if ($dto->username === null) {
            throw new InvalidArgumentException(
                'Student username is required for registered participants.',
            );
        }

        $student = $this->students->findActiveByUsernameForUpdate(
            $dto->username,
        );

        if ($student === null) {
            throw new ActiveStudentNotFoundException(
                'An active student with this username was not found.',
            );
        }

        $this->ensureStudentHasNotJoined($sessionId, $student);

        return $student->id;
    }

    private function ensureStudentHasNotJoined(
        int $sessionId,
        StudentOverview $student,
    ): void {
        if (
            $this->participants->findActiveBySessionAndStudentId(
                $sessionId,
                $student->id,
            ) !== null
        ) {
            throw new ParticipantAlreadyJoinedException(
                'This student has already joined the game.',
            );
        }
    }

    private function ensureNicknameIsAvailable(
        int $sessionId,
        string $nickname,
    ): void {
        if (
            $this->participants->findActiveBySessionAndNickname(
                $sessionId,
                $nickname,
            ) !== null
        ) {
            throw new ParticipantNicknameAlreadyExistsException(
                'This nickname is already in use in the game.',
            );
        }
    }

    private function toParticipantItem(
        SessionParticipantOverview $participant,
    ): SessionParticipantItemDTO {
        return new SessionParticipantItemDTO(
            id: $participant->id,
            sessionId: $participant->sessionId,
            participantType: $participant->participantType,
            studentId: $participant->studentId,
            nickname: $participant->nickname,
            avatarKey: $participant->avatarKey,
            totalScore: $participant->totalScore,
            isConnected: $participant->isConnected,
            isRemoved: $participant->isRemoved,
            joinedAt: $participant->joinedAt,
        );
    }
}
