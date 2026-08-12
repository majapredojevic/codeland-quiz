<?php

declare(strict_types=1);

namespace CodeLandQuiz\Question\Http;

use CodeLandQuiz\DTO\CreateQuestionDTO;
use CodeLandQuiz\DTO\QuestionOptionInputDTO;
use CodeLandQuiz\Http\JsonRequest;
use CodeLandQuiz\Model\QuestionType;
use CodeLandQuiz\QuestionImage\QuestionImagePath;
use InvalidArgumentException;
use OpenSwoole\Http\Request;

final class CreateQuestionRequest
{
    private const MAX_QUESTION_TEXT_LENGTH = 1000;
    private const MIN_TIME_LIMIT_SECONDS = 30;
    private const MAX_TIME_LIMIT_SECONDS = 300;
    private const MIN_POINTS = 1;
    private const MAX_POINTS = 10000;
    private const MAX_OPTION_TEXT_LENGTH = 255;

    public static function from(Request $request): CreateQuestionDTO
    {
        $body = JsonRequest::from($request);

        if (!$body->has('questionText')) {
            throw new InvalidArgumentException('Question text is required.');
        }

        if (!$body->has('questionType')) {
            throw new InvalidArgumentException('Question type is required.');
        }

        if (!$body->has('timeLimitSeconds')) {
            throw new InvalidArgumentException('Question time limit is required.');
        }

        if (!$body->has('maxPoints')) {
            throw new InvalidArgumentException('Question maximum points are required.');
        }

        if (!$body->has('options')) {
            throw new InvalidArgumentException('Question options are required.');
        }

        return new CreateQuestionDTO(
            questionText: self::questionTextValue($body->getValue('questionText')),
            questionType: self::questionTypeValue($body->getValue('questionType')),
            imagePath: QuestionImagePath::nullableRequestValue(
                $body->getValue('imagePath'),
            ),
            timeLimitSeconds: self::integerRangeValue(
                $body->getValue('timeLimitSeconds'),
                self::MIN_TIME_LIMIT_SECONDS,
                self::MAX_TIME_LIMIT_SECONDS,
                'Question time limit must be an integer between 30 and 300 seconds.',
            ),
            maxPoints: self::integerRangeValue(
                $body->getValue('maxPoints'),
                self::MIN_POINTS,
                self::MAX_POINTS,
                'Question maximum points must be an integer between 1 and 10000.',
            ),
            options: self::optionValues($body->getValue('options')),
        );
    }

    private static function questionTextValue(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Question text must be a string.');
        }

        $questionText = trim($value);

        if ($questionText === '') {
            throw new InvalidArgumentException('Question text cannot be empty.');
        }

        if (strlen($questionText) > self::MAX_QUESTION_TEXT_LENGTH) {
            throw new InvalidArgumentException('Question text cannot exceed 1000 characters.');
        }

        return $questionText;
    }

    private static function questionTypeValue(mixed $value): QuestionType
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Question type is invalid.');
        }

        $questionType = QuestionType::tryFrom($value);

        if ($questionType === null) {
            throw new InvalidArgumentException('Question type is invalid.');
        }

        return $questionType;
    }

    private static function integerRangeValue(
        mixed $value,
        int $minimum,
        int $maximum,
        string $message,
    ): int {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    /**
     * @return QuestionOptionInputDTO[]
     */
    private static function optionValues(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('Question options must be an array.');
        }

        return array_map(
            static fn (mixed $option): QuestionOptionInputDTO =>
                self::optionValue($option),
            $value,
        );
    }

    private static function optionValue(mixed $value): QuestionOptionInputDTO
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Each question option must be an object.');
        }

        if (!array_key_exists('optionText', $value)) {
            throw new InvalidArgumentException('Question option text is required.');
        }

        if (!array_key_exists('isCorrect', $value)) {
            throw new InvalidArgumentException('Question option correctness is required.');
        }

        return new QuestionOptionInputDTO(
            optionText: self::optionTextValue($value['optionText']),
            isCorrect: self::optionCorrectnessValue($value['isCorrect']),
        );
    }

    private static function optionTextValue(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Question option text must be a string.');
        }

        $optionText = trim($value);

        if ($optionText === '') {
            throw new InvalidArgumentException('Question option text cannot be empty.');
        }

        if (strlen($optionText) > self::MAX_OPTION_TEXT_LENGTH) {
            throw new InvalidArgumentException('Question option text cannot exceed 255 characters.');
        }

        return $optionText;
    }

    private static function optionCorrectnessValue(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('Question option correctness must be a boolean.');
        }

        return $value;
    }
}
