<?php

declare(strict_types=1);

namespace CodeLandQuiz\Quiz;

use CodeLandQuiz\Auth\AuditLogService;
use CodeLandQuiz\DTO\CreateQuizDTO;
use CodeLandQuiz\DTO\ListQuizzesDTO;
use CodeLandQuiz\DTO\QuizItemDTO;
use CodeLandQuiz\DTO\QuizListResultDTO;
use CodeLandQuiz\DTO\UpdateQuizDTO;
use CodeLandQuiz\Model\AuditAction;
use CodeLandQuiz\Model\Quiz;
use CodeLandQuiz\Model\QuizOverview;
use CodeLandQuiz\Repository\QuizRepository;
use CodeLandQuiz\Repository\TopicRepository;
use CodeLandQuiz\Support\TransactionManager;
use CodeLandQuiz\Topic\Exception\TopicNotFoundException;
use CodeLandQuiz\Quiz\Exception\QuizHasOpenSessionsException;
use CodeLandQuiz\Quiz\Exception\QuizNotFoundException;
use RuntimeException;

final readonly class QuizService
{
    private const AUDIT_ENTITY_TYPE = 'QUIZ';

    public function __construct(
        private QuizRepository $quizzes,
        private TopicRepository $topics,
        private AuditLogService $auditLogService,
        private TransactionManager $transactionManager,
    ) {
    }

    public function listQuizzes(
        ListQuizzesDTO $dto,
    ): QuizListResultDTO {
        $totalItems = $this->quizzes->count(
            $dto->search,
            $dto->topicId,
            $dto->status,
        );
        $quizzes = $this->quizzes->findPage(
            $dto->pageSize,
            $dto->getOffset(),
            $dto->search,
            $dto->topicId,
            $dto->status,
            $dto->sort,
        );
        $totalPages = $totalItems === 0
            ? 0
            : (int) ceil($totalItems / $dto->pageSize);

        return new QuizListResultDTO(
            quizzes: array_map(
                fn (QuizOverview $quiz): QuizItemDTO => $this->toQuizItem($quiz),
                $quizzes,
            ),
            pageIndex: $dto->pageIndex,
            pageSize: $dto->pageSize,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }

    public function getQuiz(int $id): QuizItemDTO
    {
        $quiz = $this->quizzes->findOverviewById($id);

        if ($quiz === null) {
            throw new QuizNotFoundException('Quiz was not found.');
        }

        return $this->toQuizItem($quiz);
    }

    public function createQuiz(
        int $actorUserId,
        CreateQuizDTO $dto,
    ): QuizItemDTO {
        $quizId = $this->transactionManager->transactional(
            function () use ($actorUserId, $dto): int {
                if (
                    $dto->topicId !== null
                    && $this->topics->findByIdForUpdate($dto->topicId) === null
                ) {
                    throw new TopicNotFoundException('Topic was not found.');
                }

                $quizId = $this->quizzes->create(
                    title: $dto->title,
                    version: $dto->version,
                    description: $dto->description,
                    topicId: $dto->topicId,
                    actorUserId: $actorUserId,
                );

                $this->auditLogService->log(
                    action: AuditAction::QUIZ_CREATED,
                    userId: $actorUserId,
                    entityType: self::AUDIT_ENTITY_TYPE,
                    entityId: $quizId,
                    metadata: [
                        'title' => $dto->title,
                        'version' => $dto->version,
                        'description' => $dto->description,
                        'topicId' => $dto->topicId,
                        'isActive' => false,
                    ],
                );

                return $quizId;
            },
        );

        $quiz = $this->quizzes->findOverviewById($quizId);

        if ($quiz === null) {
            throw new RuntimeException('Created quiz was not found.');
        }

        return $this->toQuizItem($quiz);
    }

    public function updateQuiz(
        int $actorUserId,
        int $quizId,
        UpdateQuizDTO $dto,
    ): QuizItemDTO {
        $this->transactionManager->transactional(
            function () use ($actorUserId, $dto, $quizId): void {
                if (
                    $dto->hasTopicId
                    && $dto->topicId !== null
                    && $this->topics->findByIdForUpdate($dto->topicId) === null
                ) {
                    throw new TopicNotFoundException('Topic was not found.');
                }

                $quiz = $this->quizzes->findByIdForUpdate($quizId);

                if ($quiz === null) {
                    throw new QuizNotFoundException('Quiz was not found.');
                }

                $title = $dto->hasTitle ? (string) $dto->title : $quiz->title;
                $version = $dto->hasVersion
                    ? (int) $dto->version
                    : $quiz->version;
                $description = $dto->hasDescription
                    ? $dto->description
                    : $quiz->description;
                $topicId = $dto->hasTopicId
                    ? $dto->topicId
                    : $quiz->topicId;
                $changes = $this->quizChanges(
                    $quiz,
                    $title,
                    $version,
                    $description,
                    $topicId,
                );

                if ($changes === []) {
                    return;
                }

                $this->quizzes->update(
                    id: $quiz->id,
                    title: $title,
                    version: $version,
                    description: $description,
                    topicId: $topicId,
                    actorUserId: $actorUserId,
                );

                $this->auditLogService->log(
                    action: AuditAction::QUIZ_UPDATED,
                    userId: $actorUserId,
                    entityType: self::AUDIT_ENTITY_TYPE,
                    entityId: $quiz->id,
                    metadata: [
                        'changes' => $changes,
                    ],
                );
            },
        );

        $quiz = $this->quizzes->findOverviewById($quizId);

        if ($quiz === null) {
            throw new QuizNotFoundException('Quiz was not found.');
        }

        return $this->toQuizItem($quiz);
    }

    public function deleteQuiz(
        int $actorUserId,
        int $quizId,
    ): void {
        $this->transactionManager->transactional(
            function () use ($actorUserId, $quizId): void {
                $quiz = $this->quizzes->findByIdForUpdate($quizId);

                if ($quiz === null) {
                    throw new QuizNotFoundException('Quiz was not found.');
                }

                if ($this->quizzes->hasOpenSessions($quiz->id)) {
                    throw new QuizHasOpenSessionsException(
                        'Quiz cannot be deleted while it has an open session.',
                    );
                }

                $this->quizzes->softDelete($quiz->id, $actorUserId);

                $this->auditLogService->log(
                    action: AuditAction::QUIZ_DELETED,
                    userId: $actorUserId,
                    entityType: self::AUDIT_ENTITY_TYPE,
                    entityId: $quiz->id,
                    metadata: [
                        'title' => $quiz->title,
                        'version' => $quiz->version,
                        'topicId' => $quiz->topicId,
                        'wasActive' => $quiz->isActive,
                    ],
                );
            },
        );
    }

    /**
     * @return array<string, array{from: string|int|null, to: string|int|null}>
     */
    private function quizChanges(
        Quiz $quiz,
        string $title,
        int $version,
        ?string $description,
        ?int $topicId,
    ): array {
        $changes = [];

        if ($title !== $quiz->title) {
            $changes['title'] = [
                'from' => $quiz->title,
                'to' => $title,
            ];
        }

        if ($version !== $quiz->version) {
            $changes['version'] = [
                'from' => $quiz->version,
                'to' => $version,
            ];
        }

        if ($description !== $quiz->description) {
            $changes['description'] = [
                'from' => $quiz->description,
                'to' => $description,
            ];
        }

        if ($topicId !== $quiz->topicId) {
            $changes['topicId'] = [
                'from' => $quiz->topicId,
                'to' => $topicId,
            ];
        }

        return $changes;
    }

    private function toQuizItem(QuizOverview $quiz): QuizItemDTO
    {
        return new QuizItemDTO(
            id: $quiz->id,
            title: $quiz->title,
            version: $quiz->version,
            description: $quiz->description,
            isActive: $quiz->isActive,
            questionCount: $quiz->questionCount,
            topicId: $quiz->topicId,
            topicName: $quiz->topicName,
            createdById: $quiz->createdById,
            createdByName: $quiz->createdByName,
            updatedById: $quiz->updatedById,
            updatedByName: $quiz->updatedByName,
            createdAt: $quiz->createdAt,
            updatedAt: $quiz->updatedAt,
        );
    }
}
