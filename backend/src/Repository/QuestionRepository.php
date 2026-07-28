<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\QuestionOverview;
use CodeLandQuiz\Model\QuestionType;

interface QuestionRepository
{
    /**
     * @return QuestionOverview[]
     */
    public function findAllByQuizId(int $quizId): array;

    public function findOverviewByQuizAndId(
        int $quizId,
        int $questionId,
    ): ?QuestionOverview;

    public function findOverviewByQuizAndIdForUpdate(
        int $quizId,
        int $questionId,
    ): ?QuestionOverview;

    public function getNextActiveOrder(int $quizId): int;

    public function create(
        int $quizId,
        string $questionText,
        QuestionType $questionType,
        ?string $imagePath,
        int $timeLimitSeconds,
        int $maxPoints,
        int $questionOrder,
    ): int;

    public function createOption(
        int $questionId,
        string $optionText,
        bool $isCorrect,
        int $optionOrder,
    ): int;

    public function update(
        int $questionId,
        string $questionText,
        QuestionType $questionType,
        ?string $imagePath,
        int $timeLimitSeconds,
        int $maxPoints,
    ): void;

    public function deleteOptions(int $questionId): void;

    public function softDelete(int $questionId): void;

    public function shiftActiveOrdersAfterDeletion(
        int $quizId,
        int $deletedQuestionOrder,
    ): void;

    /**
     * @return int[]
     */
    public function findActiveIdsOrderedForUpdate(int $quizId): array;

    public function moveActiveOrdersToTemporaryValues(int $quizId): void;

    public function updateQuestionOrder(
        int $quizId,
        int $questionId,
        int $questionOrder,
    ): void;

    public function countActiveByQuizId(int $quizId): int;
}
