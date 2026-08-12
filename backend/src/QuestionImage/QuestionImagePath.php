<?php

declare(strict_types=1);

namespace CodeLandQuiz\QuestionImage;

use InvalidArgumentException;

final readonly class QuestionImagePath
{
    private const MAX_PATH_LENGTH = 255;

    private const FILE_NAME_PATTERN =
        '/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/D';

    private const MEDIA_PATH_PATTERN =
        '#\A/media/question-images/(?<quizId>[1-9][0-9]*)/'
        . '(?<fileName>[a-f0-9]{32}\.(?:jpg|png|webp))\z#D';

    private function __construct(
        public int $quizId,
        public string $fileName,
    ) {
    }

    public static function fromFileName(
        int $quizId,
        string $fileName,
    ): self {
        self::ensurePositiveQuizId($quizId);
        self::ensureValidFileName($fileName);

        return new self($quizId, $fileName);
    }

    public static function fromMediaPath(string $path): self
    {
        if (
            strlen($path) > self::MAX_PATH_LENGTH
            || preg_match(self::MEDIA_PATH_PATTERN, $path, $matches) !== 1
        ) {
            throw new InvalidArgumentException(
                'Question image path must reference a managed question image.',
            );
        }

        $quizId = filter_var(
            $matches['quizId'],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if (!is_int($quizId)) {
            throw new InvalidArgumentException(
                'Question image path contains an invalid quiz identifier.',
            );
        }

        return new self($quizId, $matches['fileName']);
    }

    public static function nullableRequestValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(
                'Question image path must be a string or null.',
            );
        }

        $path = trim($value);

        if ($path === '') {
            throw new InvalidArgumentException(
                'Question image path must reference a managed question image.',
            );
        }

        self::fromMediaPath($path);

        return $path;
    }

    public function toMediaPath(): string
    {
        return sprintf(
            '/media/question-images/%d/%s',
            $this->quizId,
            $this->fileName,
        );
    }

    public function extension(): string
    {
        return (string) pathinfo($this->fileName, PATHINFO_EXTENSION);
    }

    public function assertBelongsToQuiz(int $quizId): void
    {
        self::ensurePositiveQuizId($quizId);

        if ($this->quizId !== $quizId) {
            throw new InvalidArgumentException(
                'Question image must belong to the same quiz.',
            );
        }
    }

    private static function ensureValidFileName(string $fileName): void
    {
        if (preg_match(self::FILE_NAME_PATTERN, $fileName) !== 1) {
            throw new InvalidArgumentException(
                'Question image file name is invalid.',
            );
        }
    }

    private static function ensurePositiveQuizId(int $quizId): void
    {
        if ($quizId < 1) {
            throw new InvalidArgumentException(
                'Quiz identifier must be a positive integer.',
            );
        }
    }
}
