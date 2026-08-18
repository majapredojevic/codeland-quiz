<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\ParticipantType;
use CodeLandQuiz\Model\SessionParticipantAdminOverview;
use CodeLandQuiz\Model\SessionParticipantOverview;

interface SessionParticipantRepository
{
    public function findActiveBySessionAndStudentId(
        int $sessionId,
        int $studentId,
    ): ?SessionParticipantOverview;

    public function findActiveBySessionAndNickname(
        int $sessionId,
        string $nickname,
    ): ?SessionParticipantOverview;

    /**
     * @return SessionParticipantAdminOverview[]
     */
    public function findActiveBySessionId(
        int $sessionId,
        ?int $currentSessionQuestionId,
    ): array;

    public function create(
        int $sessionId,
        ParticipantType $participantType,
        ?int $studentId,
        string $nickname,
        string $avatarKey,
    ): int;

    public function findOverviewById(
        int $participantId,
    ): ?SessionParticipantOverview;

    public function findOverviewByIdForUpdate(
        int $participantId,
    ): ?SessionParticipantOverview;

    public function findOverviewByIdForUpdateIncludingRemoved(
        int $participantId,
    ): ?SessionParticipantOverview;

    public function markConnected(int $participantId): void;

    public function markDisconnected(int $participantId): void;

    public function reconcileDisconnectedPresenceForLiveSessions(): int;

    public function markRemoved(int $participantId): void;
}
