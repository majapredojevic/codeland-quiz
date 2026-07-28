<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\Quiz;
use CodeLandQuiz\Model\QuizOverview;
use CodeLandQuiz\Model\QuizSort;
use CodeLandQuiz\Model\QuizStatusFilter;

interface QuizRepository
{
    /**
     * @return QuizOverview[]
     */
    public function findPage(
        int $limit,
        int $offset,
        ?string $search,
        ?int $topicId,
        QuizStatusFilter $status,
        QuizSort $sort,
    ): array;

    public function count(
        ?string $search,
        ?int $topicId,
        QuizStatusFilter $status,
    ): int;

    public function findOverviewById(int $id): ?QuizOverview;

    public function create(
        string $title,
        int $version,
        ?string $description,
        ?int $topicId,
        int $actorUserId,
    ): int;

    public function findByIdForUpdate(int $id): ?Quiz;

    public function update(
        int $id,
        string $title,
        int $version,
        ?string $description,
        ?int $topicId,
        int $actorUserId,
    ): void;

    public function softDelete(
        int $id,
        int $actorUserId,
    ): void;

    public function hasOpenSessions(int $quizId): bool;

    public function touch(
        int $quizId,
        int $actorUserId,
    ): void;

    public function updateActiveStatus(
        int $quizId,
        bool $isActive,
        int $actorUserId,
    ): void;
}
