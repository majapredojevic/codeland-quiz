<?php

declare(strict_types=1);

namespace CodeLandQuiz\Topic;

use CodeLandQuiz\Auth\AuditLogService;
use CodeLandQuiz\DTO\CreateTopicDTO;
use CodeLandQuiz\DTO\ListTopicsDTO;
use CodeLandQuiz\DTO\TopicItemDTO;
use CodeLandQuiz\DTO\TopicListResultDTO;
use CodeLandQuiz\DTO\UpdateTopicDTO;
use CodeLandQuiz\Model\AuditAction;
use CodeLandQuiz\Model\Topic;
use CodeLandQuiz\Model\TopicOverview;
use CodeLandQuiz\Repository\TopicRepository;
use CodeLandQuiz\Support\TransactionManager;
use CodeLandQuiz\Topic\Exception\TopicHasQuizzesException;
use CodeLandQuiz\Topic\Exception\TopicNotFoundException;
use RuntimeException;

final readonly class TopicService
{
    private const AUDIT_ENTITY_TYPE = 'TOPIC';

    public function __construct(
        private TopicRepository $topics,
        private AuditLogService $auditLogService,
        private TransactionManager $transactionManager,
    ) {
    }

    public function listTopics(
        ListTopicsDTO $dto,
    ): TopicListResultDTO {
        $totalItems = $this->topics->count($dto->search);
        $topics = $this->topics->findPage(
            $dto->pageSize,
            $dto->getOffset(),
            $dto->search,
            $dto->sort,
        );
        $totalPages = $totalItems === 0
            ? 0
            : (int) ceil($totalItems / $dto->pageSize);

        return new TopicListResultDTO(
            topics: array_map(
                fn (TopicOverview $topic): TopicItemDTO => $this->toTopicItem($topic),
                $topics,
            ),
            pageIndex: $dto->pageIndex,
            pageSize: $dto->pageSize,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }

    public function getTopic(int $id): TopicItemDTO
    {
        $topic = $this->topics->findOverviewById($id);

        if ($topic === null) {
            throw new TopicNotFoundException('Topic was not found.');
        }

        return $this->toTopicItem($topic);
    }

    public function createTopic(
        int $actorUserId,
        CreateTopicDTO $dto,
    ): TopicItemDTO {
        $topicId = $this->transactionManager->transactional(
            function () use ($actorUserId, $dto): int {
                $topicId = $this->topics->create(
                    $dto->name,
                    $dto->description,
                    $actorUserId,
                );

                $this->auditLogService->log(
                    action: AuditAction::TOPIC_CREATED,
                    userId: $actorUserId,
                    entityType: self::AUDIT_ENTITY_TYPE,
                    entityId: $topicId,
                    metadata: [
                        'name' => $dto->name,
                        'description' => $dto->description,
                    ],
                );

                return $topicId;
            },
        );
        $topic = $this->topics->findOverviewById($topicId);

        if ($topic === null) {
            throw new RuntimeException('Created topic was not found.');
        }

        return $this->toTopicItem($topic);
    }

    public function updateTopic(
        int $actorUserId,
        int $topicId,
        UpdateTopicDTO $dto,
    ): TopicItemDTO {
        $this->transactionManager->transactional(
            function () use ($actorUserId, $dto, $topicId): void {
                $topic = $this->topics->findByIdForUpdate($topicId);

                if ($topic === null) {
                    throw new TopicNotFoundException('Topic was not found.');
                }

                $name = $dto->hasName ? (string) $dto->name : $topic->name;
                $description = $dto->hasDescription
                    ? $dto->description
                    : $topic->description;
                $changes = $this->topicChanges(
                    $topic,
                    $name,
                    $description,
                );

                if ($changes === []) {
                    return;
                }

                $this->topics->update(
                    $topic->id,
                    $name,
                    $description,
                    $actorUserId,
                );

                $this->auditLogService->log(
                    action: AuditAction::TOPIC_UPDATED,
                    userId: $actorUserId,
                    entityType: self::AUDIT_ENTITY_TYPE,
                    entityId: $topic->id,
                    metadata: [
                        'changes' => $changes,
                    ],
                );
            },
        );
        $topic = $this->topics->findOverviewById($topicId);

        if ($topic === null) {
            throw new TopicNotFoundException('Topic was not found.');
        }

        return $this->toTopicItem($topic);
    }

    public function deleteTopic(
        int $actorUserId,
        int $topicId,
    ): void {
        $this->transactionManager->transactional(
            function () use ($actorUserId, $topicId): void {
                $topic = $this->topics->findByIdForUpdate($topicId);

                if ($topic === null) {
                    throw new TopicNotFoundException('Topic was not found.');
                }

                $nonDeletedQuizCount = $this->topics->countNonDeletedQuizzes(
                    $topic->id,
                );

                if ($nonDeletedQuizCount > 0) {
                    throw new TopicHasQuizzesException(
                        'Topic cannot be deleted while it contains quizzes.',
                    );
                }

                $this->topics->delete($topic->id);

                $this->auditLogService->log(
                    action: AuditAction::TOPIC_DELETED,
                    userId: $actorUserId,
                    entityType: self::AUDIT_ENTITY_TYPE,
                    entityId: $topic->id,
                    metadata: [
                        'name' => $topic->name,
                        'description' => $topic->description,
                        'nonDeletedQuizCount' => $nonDeletedQuizCount,
                    ],
                );
            },
        );
    }

    /**
     * @return array<string, array{from: string|null, to: string|null}>
     */
    private function topicChanges(
        Topic $topic,
        string $name,
        ?string $description,
    ): array {
        $changes = [];

        if ($name !== $topic->name) {
            $changes['name'] = [
                'from' => $topic->name,
                'to' => $name,
            ];
        }

        if ($description !== $topic->description) {
            $changes['description'] = [
                'from' => $topic->description,
                'to' => $description,
            ];
        }

        return $changes;
    }

    private function toTopicItem(
        TopicOverview $topic,
    ): TopicItemDTO {
        return new TopicItemDTO(
            id: $topic->id,
            name: $topic->name,
            description: $topic->description,
            quizCount: $topic->quizCount,
            createdById: $topic->createdById,
            createdByName: $topic->createdByName,
            updatedById: $topic->updatedById,
            updatedByName: $topic->updatedByName,
            createdAt: $topic->createdAt,
            updatedAt: $topic->updatedAt,
        );
    }
}
