<?php

declare(strict_types=1);

namespace CodeLandQuiz\Question;

use CodeLandQuiz\Model\QuestionOverview;
use CodeLandQuiz\Model\QuestionType;
use InvalidArgumentException;
use RuntimeException;

final class QuestionContentValidator
{
    private const MAX_QUESTION_TEXT_LENGTH = 1000;
    private const MAX_IMAGE_PATH_LENGTH = 255;
    private const MIN_TIME_LIMIT_SECONDS = 30;
    private const MAX_TIME_LIMIT_SECONDS = 300;
    private const MIN_POINTS = 1;
    private const MAX_POINTS = 10000;

    /**
     * @param string[] $optionTexts
     * @param bool[] $correctStates
     */
    public function validateOptions(
        QuestionType $questionType,
        array $optionTexts,
        array $correctStates,
    ): void {
        if (count($optionTexts) !== count($correctStates)) {
            throw new RuntimeException(
                'Question option text and correctness arrays must have the same length.',
            );
        }

        $this->ensureUniqueOptionTexts($optionTexts);
        $correctOptionCount = $this->correctOptionCount($correctStates);

        if ($questionType === QuestionType::TRUE_FALSE) {
            $this->validateTrueFalseOptions(
                $optionTexts,
                $correctOptionCount,
            );

            return;
        }

        if ($questionType === QuestionType::SINGLE_CHOICE) {
            $this->validateSingleChoiceOptions(
                $optionTexts,
                $correctOptionCount,
            );

            return;
        }

        $this->validateMultipleChoiceOptions(
            $optionTexts,
            $correctOptionCount,
        );
    }

    public function validateStoredQuestion(QuestionOverview $question): void
    {
        $questionText = trim($question->questionText);

        if ($questionText === '') {
            throw new InvalidArgumentException('Question text cannot be empty.');
        }

        if (strlen($questionText) > self::MAX_QUESTION_TEXT_LENGTH) {
            throw new InvalidArgumentException('Question text cannot exceed 1000 characters.');
        }

        if (
            $question->imagePath !== null
            && strlen($question->imagePath) > self::MAX_IMAGE_PATH_LENGTH
        ) {
            throw new InvalidArgumentException('Question image path cannot exceed 255 characters.');
        }

        if (
            $question->timeLimitSeconds < self::MIN_TIME_LIMIT_SECONDS
            || $question->timeLimitSeconds > self::MAX_TIME_LIMIT_SECONDS
        ) {
            throw new InvalidArgumentException(
                'Question time limit must be an integer between 30 and 300 seconds.',
            );
        }

        if (
            $question->maxPoints < self::MIN_POINTS
            || $question->maxPoints > self::MAX_POINTS
        ) {
            throw new InvalidArgumentException(
                'Question maximum points must be an integer between 1 and 10000.',
            );
        }

        $this->validateOptions(
            $question->questionType,
            array_map(
                static fn ($option): string => $option->optionText,
                $question->options,
            ),
            array_map(
                static fn ($option): bool => $option->isCorrect,
                $question->options,
            ),
        );
    }

    /**
     * @param string[] $optionTexts
     */
    private function ensureUniqueOptionTexts(array $optionTexts): void
    {
        $seenOptionTexts = [];

        foreach ($optionTexts as $optionText) {
            $normalizedText = mb_strtolower($optionText, 'UTF-8');

            if (isset($seenOptionTexts[$normalizedText])) {
                throw new InvalidArgumentException(
                    'Question option texts must be unique.',
                );
            }

            $seenOptionTexts[$normalizedText] = true;
        }
    }

    /**
     * @param string[] $optionTexts
     */
    private function validateTrueFalseOptions(
        array $optionTexts,
        int $correctOptionCount,
    ): void {
        if (count($optionTexts) !== 2) {
            throw new InvalidArgumentException(
                'TRUE_FALSE questions must have exactly two options.',
            );
        }

        if (
            $optionTexts[0] !== 'Tačno'
            || $optionTexts[1] !== 'Netačno'
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
     * @param string[] $optionTexts
     */
    private function validateSingleChoiceOptions(
        array $optionTexts,
        int $correctOptionCount,
    ): void {
        if (!in_array(count($optionTexts), [2, 4], true)) {
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
     * @param string[] $optionTexts
     */
    private function validateMultipleChoiceOptions(
        array $optionTexts,
        int $correctOptionCount,
    ): void {
        if (count($optionTexts) !== 4) {
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
     * @param bool[] $correctStates
     */
    private function correctOptionCount(array $correctStates): int
    {
        return count(array_filter(
            $correctStates,
            static fn (bool $isCorrect): bool => $isCorrect,
        ));
    }
}
