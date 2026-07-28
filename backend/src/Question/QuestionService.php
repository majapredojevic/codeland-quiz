<?php

declare(strict_types=1);

namespace CodeLandQuiz\Question;

use CodeLandQuiz\Auth\AuditLogService;
use CodeLandQuiz\DTO\CreateQuestionDTO;
use CodeLandQuiz\DTO\QuestionItemDTO;
use CodeLandQuiz\DTO\QuestionOptionInputDTO;
use CodeLandQuiz\DTO\QuestionOptionItemDTO;
use CodeLandQuiz\DTO\UpdateQuestionDTO;
use CodeLandQuiz\Model\AuditAction;
use CodeLandQuiz\Model\QuestionOptionOverview;
use CodeLandQuiz\Model\QuestionOverview;
use CodeLandQuiz\Model\QuestionType;
use CodeLandQuiz\Question\Exception\QuizContentLockedException;
use CodeLandQuiz\Question\Exception\QuestionNotFoundException;
use CodeLandQuiz\Quiz\Exception\QuizNotFoundException;
use CodeLandQuiz\Repository\QuestionRepository;
use CodeLandQuiz\Repository\QuizRepository;
use CodeLandQuiz\Support\TransactionManager;
use InvalidArgumentException;
use RuntimeException;

final readonly class QuestionService
{
    private const AUDIT_ENTITY_TYPE = 'QUESTION';

    public function __construct(
        private QuestionRepository $questions,
        private QuizRepository $quizzes,
        private AuditLogService $auditLogService,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @return QuestionItemDTO[]
     */
    public function listQuestions(int $quizId): array
    {
        $this->ensureQuizExists($quizId);

        return array_map(
            fn (QuestionOverview $question): QuestionItemDTO =>
                $this->toQuestionItem($question),
            $this->questions->findAllByQuizId($quizId),
        );
    }

    public function getQuestion(
        int $quizId,
        int $questionId,
    ): QuestionItemDTO {
        $this->ensureQuizExists($quizId);

        $question = $this->questions->findOverviewByQuizAndId(
            $quizId,
            $questionId,
        );

        if ($question === null) {
            throw new QuestionNotFoundException('Question was not found.');
        }

        return $this->toQuestionItem($question);
    }

    public function createQuestion(
        int $actorUserId,
        int $quizId,
        CreateQuestionDTO $dto,
    ): QuestionItemDTO {
        $this->validateOptions($dto->questionType, $dto->options);

        $questionId = $this->transactionManager->transactional(
            function () use ($actorUserId, $dto, $quizId): int {
                $quiz = $this->quizzes->findByIdForUpdate($quizId);

                if ($quiz === null) {
                    throw new QuizNotFoundException('Quiz was not found.');
                }

                if ($this->quizzes->hasOpenSessions($quizId)) {
                    throw new QuizContentLockedException(
                        'Quiz content cannot be changed while it has an open session.',
                    );
                }

                $questionOrder = $this->questions->getNextActiveOrder($quizId);
                $questionId = $this->questions->create(
                    quizId: $quizId,
                    questionText: $dto->questionText,
                    questionType: $dto->questionType,
                    imagePath: $dto->imagePath,
                    timeLimitSeconds: $dto->timeLimitSeconds,
                    maxPoints: $dto->maxPoints,
                    questionOrder: $questionOrder,
                );

                foreach ($dto->options as $index => $option) {
                    $this->questions->createOption(
                        questionId: $questionId,
                        optionText: $option->optionText,
                        isCorrect: $option->isCorrect,
                        optionOrder: $index + 1,
                    );
                }

                $this->quizzes->touch($quizId, $actorUserId);

                $this->auditLogService->log(
                    action: AuditAction::QUESTION_CREATED,
                    userId: $actorUserId,
                    entityType: self::AUDIT_ENTITY_TYPE,
                    entityId: $questionId,
                    metadata: [
                        'quizId' => $quizId,
                        'questionType' => $dto->questionType->value,
                        'questionOrder' => $questionOrder,
                        'timeLimitSeconds' => $dto->timeLimitSeconds,
                        'maxPoints' => $dto->maxPoints,
                        'imagePath' => $dto->imagePath,
                        'optionCount' => count($dto->options),
                        'correctOptionCount' => $this->correctOptionCount(
                            $dto->options,
                        ),
                    ],
                );

                return $questionId;
            },
        );

        $question = $this->questions->findOverviewByQuizAndId(
            $quizId,
            $questionId,
        );

        if ($question === null) {
            throw new RuntimeException('Created question was not found.');
        }

        return $this->toQuestionItem($question);
    }

    public function updateQuestion(
        int $actorUserId,
        int $quizId,
        int $questionId,
        UpdateQuestionDTO $dto,
    ): QuestionItemDTO {
        $this->transactionManager->transactional(
            function () use ($actorUserId, $dto, $questionId, $quizId): void {
                $quiz = $this->quizzes->findByIdForUpdate($quizId);

                if ($quiz === null) {
                    throw new QuizNotFoundException('Quiz was not found.');
                }

                if ($this->quizzes->hasOpenSessions($quizId)) {
                    throw new QuizContentLockedException(
                        'Quiz content cannot be changed while it has an open session.',
                    );
                }

                $question = $this->questions->findOverviewByQuizAndIdForUpdate(
                    $quizId,
                    $questionId,
                );

                if ($question === null) {
                    throw new QuestionNotFoundException('Question was not found.');
                }

                $questionText = $dto->hasQuestionText
                    ? (string) $dto->questionText
                    : $question->questionText;
                $questionType = $dto->hasQuestionType
                    ? $dto->questionType
                    : $question->questionType;
                $imagePath = $dto->hasImagePath
                    ? $dto->imagePath
                    : $question->imagePath;
                $timeLimitSeconds = $dto->hasTimeLimitSeconds
                    ? (int) $dto->timeLimitSeconds
                    : $question->timeLimitSeconds;
                $maxPoints = $dto->hasMaxPoints
                    ? (int) $dto->maxPoints
                    : $question->maxPoints;
                $options = $dto->hasOptions
                    ? (array) $dto->options
                    : $this->toOptionInputs($question->options);

                $this->validateOptions($questionType, $options);

                $changedFields = $this->changedScalarFields(
                    $question,
                    $questionText,
                    $questionType,
                    $imagePath,
                    $timeLimitSeconds,
                    $maxPoints,
                );
                $optionsChanged = !$this->optionsEqual(
                    $question->options,
                    $options,
                );

                if ($optionsChanged) {
                    $changedFields[] = 'options';
                }

                if ($changedFields === []) {
                    return;
                }

                if ($this->hasScalarChanges($changedFields)) {
                    $this->questions->update(
                        questionId: $question->id,
                        questionText: $questionText,
                        questionType: $questionType,
                        imagePath: $imagePath,
                        timeLimitSeconds: $timeLimitSeconds,
                        maxPoints: $maxPoints,
                    );
                }

                if ($optionsChanged) {
                    $this->questions->deleteOptions($question->id);

                    foreach ($options as $index => $option) {
                        $this->questions->createOption(
                            questionId: $question->id,
                            optionText: $option->optionText,
                            isCorrect: $option->isCorrect,
                            optionOrder: $index + 1,
                        );
                    }
                }

                $this->quizzes->touch($quizId, $actorUserId);

                $this->auditLogService->log(
                    action: AuditAction::QUESTION_UPDATED,
                    userId: $actorUserId,
                    entityType: self::AUDIT_ENTITY_TYPE,
                    entityId: $question->id,
                    metadata: [
                        'quizId' => $quizId,
                        'changedFields' => $changedFields,
                        'questionType' => $questionType->value,
                        'questionOrder' => $question->questionOrder,
                        'timeLimitSeconds' => $timeLimitSeconds,
                        'maxPoints' => $maxPoints,
                        'optionCount' => count($options),
                        'correctOptionCount' => $this->correctOptionCount(
                            $options,
                        ),
                    ],
                );
            },
        );

        $question = $this->questions->findOverviewByQuizAndId(
            $quizId,
            $questionId,
        );

        if ($question === null) {
            throw new RuntimeException('Updated question was not found.');
        }

        return $this->toQuestionItem($question);
    }

    public function deleteQuestion(
        int $actorUserId,
        int $quizId,
        int $questionId,
    ): void {
        $this->transactionManager->transactional(
            function () use ($actorUserId, $questionId, $quizId): void {
                $quiz = $this->quizzes->findByIdForUpdate($quizId);

                if ($quiz === null) {
                    throw new QuizNotFoundException('Quiz was not found.');
                }

                if ($this->quizzes->hasOpenSessions($quizId)) {
                    throw new QuizContentLockedException(
                        'Quiz content cannot be changed while it has an open session.',
                    );
                }

                $question = $this->questions->findOverviewByQuizAndIdForUpdate(
                    $quizId,
                    $questionId,
                );

                if ($question === null) {
                    throw new QuestionNotFoundException('Question was not found.');
                }

                $this->questions->softDelete($question->id);
                $this->questions->shiftActiveOrdersAfterDeletion(
                    $quizId,
                    $question->questionOrder,
                );
                $this->quizzes->touch($quizId, $actorUserId);

                $this->auditLogService->log(
                    action: AuditAction::QUESTION_DELETED,
                    userId: $actorUserId,
                    entityType: self::AUDIT_ENTITY_TYPE,
                    entityId: $question->id,
                    metadata: [
                        'quizId' => $quizId,
                        'questionType' => $question->questionType->value,
                        'deletedQuestionOrder' => $question->questionOrder,
                        'optionCount' => count($question->options),
                    ],
                );
            },
        );
    }

    private function ensureQuizExists(int $quizId): void
    {
        if ($this->quizzes->findOverviewById($quizId) === null) {
            throw new QuizNotFoundException('Quiz was not found.');
        }
    }

    /**
     * @param QuestionOptionInputDTO[] $options
     */
    private function validateOptions(
        QuestionType $questionType,
        array $options,
    ): void {
        $this->ensureUniqueOptionTexts($options);
        $correctOptionCount = $this->correctOptionCount($options);

        if ($questionType === QuestionType::TRUE_FALSE) {
            $this->validateTrueFalseOptions($options, $correctOptionCount);

            return;
        }

        if ($questionType === QuestionType::SINGLE_CHOICE) {
            $this->validateSingleChoiceOptions($options, $correctOptionCount);

            return;
        }

        $this->validateMultipleChoiceOptions($options, $correctOptionCount);
    }

    /**
     * @param QuestionOptionInputDTO[] $options
     */
    private function ensureUniqueOptionTexts(array $options): void
    {
        $seenOptionTexts = [];

        foreach ($options as $option) {
            $normalizedText = mb_strtolower($option->optionText, 'UTF-8');

            if (isset($seenOptionTexts[$normalizedText])) {
                throw new InvalidArgumentException(
                    'Question option texts must be unique.',
                );
            }

            $seenOptionTexts[$normalizedText] = true;
        }
    }

    /**
     * @param QuestionOptionInputDTO[] $options
     */
    private function validateTrueFalseOptions(
        array $options,
        int $correctOptionCount,
    ): void {
        if (count($options) !== 2) {
            throw new InvalidArgumentException(
                'TRUE_FALSE questions must have exactly two options.',
            );
        }

        if (
            $options[0]->optionText !== 'Tačno'
            || $options[1]->optionText !== 'Netačno'
        ) {
            throw new InvalidArgumentException(
                'TRUE_FALSE options must be "Tačno" and "Netačno" in that order.',
            );
        }

        if ($correctOptionCount !== 1) {
            throw new InvalidArgumentException(
                'TRUE_FALSE questions must have exactly one correct option.',
            );
        }
    }

    /**
     * @param QuestionOptionInputDTO[] $options
     */
    private function validateSingleChoiceOptions(
        array $options,
        int $correctOptionCount,
    ): void {
        if (!in_array(count($options), [2, 4], true)) {
            throw new InvalidArgumentException(
                'SINGLE_CHOICE questions must have exactly two or four options.',
            );
        }

        if ($correctOptionCount !== 1) {
            throw new InvalidArgumentException(
                'SINGLE_CHOICE questions must have exactly one correct option.',
            );
        }
    }

    /**
     * @param QuestionOptionInputDTO[] $options
     */
    private function validateMultipleChoiceOptions(
        array $options,
        int $correctOptionCount,
    ): void {
        if (count($options) !== 4) {
            throw new InvalidArgumentException(
                'MULTIPLE_CHOICE questions must have exactly four options.',
            );
        }

        if (!in_array($correctOptionCount, [2, 3], true)) {
            throw new InvalidArgumentException(
                'MULTIPLE_CHOICE questions must have two or three correct options.',
            );
        }
    }

    /**
     * @param QuestionOptionInputDTO[] $options
     */
    private function correctOptionCount(array $options): int
    {
        return count(array_filter(
            $options,
            static fn (QuestionOptionInputDTO $option): bool =>
                $option->isCorrect,
        ));
    }

    /**
     * @param QuestionOptionOverview[] $options
     *
     * @return QuestionOptionInputDTO[]
     */
    private function toOptionInputs(array $options): array
    {
        return array_map(
            static fn (QuestionOptionOverview $option): QuestionOptionInputDTO =>
                new QuestionOptionInputDTO(
                    optionText: trim($option->optionText),
                    isCorrect: $option->isCorrect,
                ),
            $options,
        );
    }

    /**
     * @return string[]
     */
    private function changedScalarFields(
        QuestionOverview $question,
        string $questionText,
        QuestionType $questionType,
        ?string $imagePath,
        int $timeLimitSeconds,
        int $maxPoints,
    ): array {
        $changedFields = [];

        if ($questionText !== $question->questionText) {
            $changedFields[] = 'questionText';
        }

        if ($questionType !== $question->questionType) {
            $changedFields[] = 'questionType';
        }

        if ($imagePath !== $question->imagePath) {
            $changedFields[] = 'imagePath';
        }

        if ($timeLimitSeconds !== $question->timeLimitSeconds) {
            $changedFields[] = 'timeLimitSeconds';
        }

        if ($maxPoints !== $question->maxPoints) {
            $changedFields[] = 'maxPoints';
        }

        return $changedFields;
    }

    /**
     * @param QuestionOptionOverview[] $existingOptions
     * @param QuestionOptionInputDTO[] $resolvedOptions
     */
    private function optionsEqual(
        array $existingOptions,
        array $resolvedOptions,
    ): bool {
        if (count($existingOptions) !== count($resolvedOptions)) {
            return false;
        }

        foreach ($existingOptions as $index => $existingOption) {
            $resolvedOption = $resolvedOptions[$index] ?? null;

            if ($resolvedOption === null) {
                return false;
            }

            if (trim($existingOption->optionText) !== $resolvedOption->optionText) {
                return false;
            }

            if ($existingOption->isCorrect !== $resolvedOption->isCorrect) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string[] $changedFields
     */
    private function hasScalarChanges(array $changedFields): bool
    {
        return array_values(array_diff($changedFields, ['options'])) !== [];
    }

    private function toQuestionItem(
        QuestionOverview $question,
    ): QuestionItemDTO {
        return new QuestionItemDTO(
            id: $question->id,
            quizId: $question->quizId,
            questionText: $question->questionText,
            questionType: $question->questionType,
            imagePath: $question->imagePath,
            timeLimitSeconds: $question->timeLimitSeconds,
            maxPoints: $question->maxPoints,
            questionOrder: $question->questionOrder,
            options: array_map(
                fn (QuestionOptionOverview $option): QuestionOptionItemDTO =>
                    $this->toOptionItem($option),
                $question->options,
            ),
            createdAt: $question->createdAt,
            updatedAt: $question->updatedAt,
        );
    }

    private function toOptionItem(
        QuestionOptionOverview $option,
    ): QuestionOptionItemDTO {
        return new QuestionOptionItemDTO(
            id: $option->id,
            optionText: $option->optionText,
            isCorrect: $option->isCorrect,
            optionOrder: $option->optionOrder,
        );
    }
}
