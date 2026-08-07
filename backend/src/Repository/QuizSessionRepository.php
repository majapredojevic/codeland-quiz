<?php

declare(strict_types=1);

namespace CodeLandQuiz\Repository;

use CodeLandQuiz\Model\QuestionType;
use CodeLandQuiz\Model\QuizSessionOverview;

interface QuizSessionRepository
{
    public function create(
        int $quizId,
        int $hostUserId,
        string $quizTitle,
        int $quizVersion,
        string $gamePin,
    ): int;

    public function createSnapshotQuestion(
        int $sessionId,
        int $sourceQuestionId,
        string $questionText,
        QuestionType $questionType,
        ?string $imagePath,
        int $timeLimitSeconds,
        int $maxPoints,
        int $questionOrder,
    ): int;

    public function createSnapshotOption(
        int $sessionQuestionId,
        int $sourceOptionId,
        string $optionText,
        bool $isCorrect,
        int $optionOrder,
    ): int;

    public function findOverviewById(
        int $sessionId,
    ): ?QuizSessionOverview;

    public function findOverviewByIdForUpdate(
        int $sessionId,
    ): ?QuizSessionOverview;

    public function findOverviewByActiveGamePin(
        string $gamePin,
    ): ?QuizSessionOverview;

    public function findOverviewByActiveGamePinForUpdate(
        string $gamePin,
    ): ?QuizSessionOverview;

    public function markStarted(
        int $sessionId,
        int $questionOrder,
        int $timeLimitSeconds,
    ): void;

    public function markCurrentQuestionClosed(
        int $sessionId,
    ): void;

    public function markNextQuestionStarted(
        int $sessionId,
        int $expectedCurrentQuestionOrder,
        int $nextQuestionOrder,
        int $timeLimitSeconds,
    ): bool;

    public function markFinished(int $sessionId): bool;
}
