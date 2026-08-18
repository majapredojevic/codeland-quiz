<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use Closure;
use CodeLandQuiz\Model\ParticipantType;
use DateTimeImmutable;
use RuntimeException;

final class ParticipantConnectionRegistry
{
    private Closure $monotonicClock;

    /**
     * @var array<int, string>
     */
    private array $pendingConnectionIds = [];

    /**
     * @var array<int, AuthenticatedParticipantConnection>
     */
    private array $authenticatedConnections = [];

    /**
     * @var array<int, int>
     */
    private array $currentFileDescriptorByParticipantId = [];

    /**
     * @var array<int, array<int, true>>
     */
    private array $fileDescriptorsBySessionId = [];

    /**
     * @var array<int, int>
     */
    private array $answeredQuestionOrderByFileDescriptor = [];

    public function __construct(?Closure $monotonicClock = null)
    {
        $this->monotonicClock = $monotonicClock
            ?? static fn (): int => hrtime(true);
    }

    public function registerPending(int $fileDescriptor): string
    {
        $this->remove($fileDescriptor);

        $connectionId = bin2hex(random_bytes(16));
        $this->pendingConnectionIds[$fileDescriptor] = $connectionId;

        return $connectionId;
    }

    public function isPending(
        int $fileDescriptor,
        string $connectionId,
    ): bool {
        return ($this->pendingConnectionIds[$fileDescriptor] ?? null)
            === $connectionId;
    }

    public function authenticate(
        int $fileDescriptor,
        string $connectionId,
        int $participantId,
        int $sessionId,
        ParticipantType $participantType,
        ?int $studentId,
        DateTimeImmutable $participantTokenExpiresAt,
    ): ?int {
        if (!$this->isPending($fileDescriptor, $connectionId)) {
            throw new RuntimeException(
                'Pending participant connection was not found.',
            );
        }

        unset($this->pendingConnectionIds[$fileDescriptor]);

        $previousFileDescriptor =
            $this->currentFileDescriptorByParticipantId[$participantId]
            ?? null;

        if ($previousFileDescriptor !== null) {
            $this->removeAuthenticated($previousFileDescriptor);
        }

        $connection = new AuthenticatedParticipantConnection(
            fileDescriptor: $fileDescriptor,
            connectionId: $connectionId,
            participantId: $participantId,
            sessionId: $sessionId,
            participantType: $participantType,
            studentId: $studentId,
            participantTokenExpiresAt: $participantTokenExpiresAt,
            lastSeenMonotonicNanoseconds: ($this->monotonicClock)(),
        );

        $this->authenticatedConnections[$fileDescriptor] = $connection;
        $this->currentFileDescriptorByParticipantId[$participantId] =
            $fileDescriptor;
        $this->fileDescriptorsBySessionId[$sessionId][$fileDescriptor] = true;

        return $previousFileDescriptor;
    }

    public function findAuthenticated(
        int $fileDescriptor,
    ): ?AuthenticatedParticipantConnection {
        return $this->authenticatedConnections[$fileDescriptor] ?? null;
    }

    public function findCurrentFileDescriptorByParticipantId(
        int $participantId,
    ): ?int {
        return $this->currentFileDescriptorByParticipantId[$participantId]
            ?? null;
    }

    public function remove(
        int $fileDescriptor,
    ): ?AuthenticatedParticipantConnection {
        unset($this->pendingConnectionIds[$fileDescriptor]);

        $connection = $this->removeAuthenticated($fileDescriptor);

        if ($connection === null) {
            return null;
        }

        if (
            ($this->currentFileDescriptorByParticipantId[$connection->participantId]
                ?? null) !== $fileDescriptor
        ) {
            return null;
        }

        unset(
            $this->currentFileDescriptorByParticipantId[
                $connection->participantId
            ],
        );

        return $connection;
    }

    public function removeIfCurrent(
        int $fileDescriptor,
        string $connectionId,
    ): ?AuthenticatedParticipantConnection {
        if (!$this->isCurrent($fileDescriptor, $connectionId)) {
            return null;
        }

        return $this->remove($fileDescriptor);
    }

    public function isCurrent(
        int $fileDescriptor,
        string $connectionId,
    ): bool {
        $connection = $this->authenticatedConnections[$fileDescriptor] ?? null;

        return $connection !== null
            && hash_equals($connection->connectionId, $connectionId)
            && ($this->currentFileDescriptorByParticipantId[
                $connection->participantId
            ] ?? null) === $fileDescriptor;
    }

    public function touchAuthenticated(
        int $fileDescriptor,
        string $connectionId,
    ): bool {
        if (!$this->isCurrent($fileDescriptor, $connectionId)) {
            return false;
        }

        $this->authenticatedConnections[$fileDescriptor]->touch(
            ($this->monotonicClock)(),
        );

        return true;
    }

    /**
     * @return AuthenticatedParticipantConnection[]
     */
    public function authenticatedConnections(): array
    {
        return array_values($this->authenticatedConnections);
    }

    public function countPending(): int
    {
        return count($this->pendingConnectionIds);
    }

    public function countAuthenticated(): int
    {
        return count($this->authenticatedConnections);
    }

    /**
     * @return int[]
     */
    public function findSessionFileDescriptors(int $sessionId): array
    {
        return array_map(
            static fn(int|string $fileDescriptor): int => (int) $fileDescriptor,
            array_keys($this->fileDescriptorsBySessionId[$sessionId] ?? []),
        );
    }

    public function isAuthenticated(int $fileDescriptor): bool
    {
        return isset($this->authenticatedConnections[$fileDescriptor]);
    }

    public function markAnswerAccepted(
        int $fileDescriptor,
        int $questionOrder,
    ): void {
        if (!$this->isAuthenticated($fileDescriptor)) {
            return;
        }

        $this->answeredQuestionOrderByFileDescriptor[$fileDescriptor] =
            $questionOrder;
    }

    public function hasAcceptedCurrentAnswer(int $fileDescriptor): bool
    {
        return isset(
            $this->answeredQuestionOrderByFileDescriptor[$fileDescriptor],
        );
    }

    public function clearAcceptedAnswersForSession(int $sessionId): void
    {
        foreach ($this->findSessionFileDescriptors($sessionId) as $fileDescriptor) {
            unset($this->answeredQuestionOrderByFileDescriptor[$fileDescriptor]);
        }
    }

    private function removeAuthenticated(
        int $fileDescriptor,
    ): ?AuthenticatedParticipantConnection {
        $connection = $this->authenticatedConnections[$fileDescriptor] ?? null;

        if ($connection === null) {
            return null;
        }

        unset($this->authenticatedConnections[$fileDescriptor]);
        unset($this->answeredQuestionOrderByFileDescriptor[$fileDescriptor]);
        unset(
            $this->fileDescriptorsBySessionId[
                $connection->sessionId
            ][$fileDescriptor],
        );

        if (($this->fileDescriptorsBySessionId[$connection->sessionId] ?? []) === []) {
            unset($this->fileDescriptorsBySessionId[$connection->sessionId]);
        }

        return $connection;
    }
}
