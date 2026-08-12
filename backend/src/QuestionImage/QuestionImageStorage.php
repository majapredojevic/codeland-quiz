<?php

declare(strict_types=1);

namespace CodeLandQuiz\QuestionImage;

use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\QuestionImage\Exception\QuestionImageNotFoundException;
use CodeLandQuiz\QuestionImage\Exception\QuestionImageUploadTooLargeException;
use finfo;
use InvalidArgumentException;
use RuntimeException;

final readonly class QuestionImageStorage
{
    /**
     * @var array<string, string>
     */
    private const MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @var array<string, string[]>
     */
    private const MIME_TO_CLIENT_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /**
     * @var array<string, string>
     */
    private const EXTENSION_TO_MIME = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    private const FILE_NAME_RANDOM_BYTES = 16;

    private const MAX_FILE_NAME_ATTEMPTS = 5;

    public function __construct(
        private AppConfig $config,
    ) {
    }

    /**
     * @param array<string, mixed> $uploadedFile
     */
    public function store(int $quizId, array $uploadedFile): QuestionImageAsset
    {
        $this->ensurePositiveQuizId($quizId);
        $upload = $this->validatedUpload($uploadedFile);
        $mimeType = $this->verifiedMimeType($upload['temporaryPath']);
        $this->ensureExtensionMatchesMimeType(
            $upload['originalName'],
            $mimeType,
        );

        $extension = self::MIME_TO_EXTENSION[$mimeType];
        $quizDirectory = $this->ensureQuizDirectory($quizId);

        for ($attempt = 1; $attempt <= self::MAX_FILE_NAME_ATTEMPTS; $attempt++) {
            $fileName = bin2hex(random_bytes(self::FILE_NAME_RANDOM_BYTES))
                . '.' . $extension;
            $destinationPath = $quizDirectory
                . DIRECTORY_SEPARATOR . $fileName;
            $destination = @fopen($destinationPath, 'xb');

            if ($destination === false) {
                if (file_exists($destinationPath)) {
                    continue;
                }

                throw new RuntimeException(
                    'Question image could not be stored.',
                );
            }

            try {
                $source = @fopen($upload['temporaryPath'], 'rb');

                if ($source === false) {
                    throw new RuntimeException(
                        'Question image upload could not be read.',
                    );
                }

                try {
                    $copiedBytes = stream_copy_to_stream($source, $destination);
                } finally {
                    fclose($source);
                }

                if (
                    $copiedBytes === false
                    || $copiedBytes !== $upload['size']
                    || !fflush($destination)
                ) {
                    throw new RuntimeException(
                        'Question image could not be stored completely.',
                    );
                }
            } catch (\Throwable $throwable) {
                fclose($destination);
                @unlink($destinationPath);

                throw $throwable;
            }

            fclose($destination);
            @chmod($destinationPath, 0640);

            $managedPath = QuestionImagePath::fromFileName(
                $quizId,
                $fileName,
            );

            return new QuestionImageAsset(
                fileName: $fileName,
                path: $managedPath->toMediaPath(),
            );
        }

        throw new RuntimeException(
            'A unique question image file name could not be generated.',
        );
    }

    public function assertManagedImageExists(
        int $quizId,
        string $mediaPath,
    ): void {
        $managedPath = QuestionImagePath::fromMediaPath($mediaPath);
        $managedPath->assertBelongsToQuiz($quizId);

        if (!$this->isStoredFile($managedPath)) {
            throw new InvalidArgumentException(
                'Question image path does not reference an available managed image.',
            );
        }
    }

    public function publicFile(
        int $quizId,
        string $fileName,
    ): QuestionImageFile {
        $managedPath = QuestionImagePath::fromFileName($quizId, $fileName);

        if (!$this->isStoredFile($managedPath)) {
            throw new QuestionImageNotFoundException(
                'Question image was not found.',
            );
        }

        $contentType = self::EXTENSION_TO_MIME[$managedPath->extension()]
            ?? null;

        if ($contentType === null) {
            throw new QuestionImageNotFoundException(
                'Question image was not found.',
            );
        }

        return new QuestionImageFile(
            physicalPath: $this->physicalPath($managedPath),
            contentType: $contentType,
        );
    }

    public function delete(
        int $quizId,
        string $fileName,
    ): void {
        $managedPath = QuestionImagePath::fromFileName($quizId, $fileName);
        $physicalPath = $this->physicalPath($managedPath);

        if (!$this->isStoredFile($managedPath)) {
            throw new QuestionImageNotFoundException(
                'Question image was not found.',
            );
        }

        if (!@unlink($physicalPath)) {
            throw new RuntimeException(
                'Question image could not be removed.',
            );
        }

        $quizDirectory = dirname($physicalPath);

        if ($this->directoryIsEmpty($quizDirectory)) {
            @rmdir($quizDirectory);
        }
    }

    /**
     * @param array<string, mixed> $uploadedFile
     *
     * @return array{originalName: string, temporaryPath: string, size: int}
     */
    private function validatedUpload(array $uploadedFile): array
    {
        $originalName = $uploadedFile['name'] ?? null;
        $temporaryPath = $uploadedFile['tmp_name'] ?? null;
        $error = $uploadedFile['error'] ?? null;
        $declaredSize = $uploadedFile['size'] ?? null;

        if (
            !is_string($originalName)
            || $originalName === ''
            || str_contains($originalName, "\0")
            || !is_string($temporaryPath)
            || $temporaryPath === ''
            || !is_int($error)
            || !is_int($declaredSize)
        ) {
            throw new InvalidArgumentException(
                'Question image upload is malformed.',
            );
        }

        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $this->throwTooLarge();
        }

        if ($error !== UPLOAD_ERR_OK || $declaredSize < 1) {
            throw new InvalidArgumentException(
                'Question image upload is malformed.',
            );
        }

        if ($declaredSize > $this->config->getMaximumUploadSizeBytes()) {
            $this->throwTooLarge();
        }

        if (
            !is_file($temporaryPath)
            || is_link($temporaryPath)
            || !is_readable($temporaryPath)
        ) {
            throw new InvalidArgumentException(
                'Question image upload is malformed.',
            );
        }

        $actualSize = filesize($temporaryPath);

        if (!is_int($actualSize) || $actualSize < 1) {
            throw new InvalidArgumentException(
                'Question image upload is malformed.',
            );
        }

        if ($actualSize > $this->config->getMaximumUploadSizeBytes()) {
            $this->throwTooLarge();
        }

        if ($actualSize !== $declaredSize) {
            throw new InvalidArgumentException(
                'Question image upload is malformed.',
            );
        }

        return [
            'originalName' => $originalName,
            'temporaryPath' => $temporaryPath,
            'size' => $actualSize,
        ];
    }

    private function verifiedMimeType(string $temporaryPath): string
    {
        $detectedMimeType = (new finfo(FILEINFO_MIME_TYPE))->file(
            $temporaryPath,
        );

        if (
            !is_string($detectedMimeType)
            || !array_key_exists($detectedMimeType, self::MIME_TO_EXTENSION)
            || !$this->mimeTypeIsConfigured($detectedMimeType)
        ) {
            throw new InvalidArgumentException(
                'Uploaded file is not a supported image.',
            );
        }

        $imageMetadata = @getimagesize($temporaryPath);

        if (
            $imageMetadata === false
            || ($imageMetadata['mime'] ?? null) !== $detectedMimeType
            || ($imageMetadata[0] ?? 0) < 1
            || ($imageMetadata[1] ?? 0) < 1
        ) {
            throw new InvalidArgumentException(
                'Uploaded file is not a supported image.',
            );
        }

        return $detectedMimeType;
    }

    private function ensureExtensionMatchesMimeType(
        string $originalName,
        string $mimeType,
    ): void {
        $extension = strtolower(
            (string) pathinfo($originalName, PATHINFO_EXTENSION),
        );
        $configuredExtensions = $this->config->getAllowedImageExtensions();
        $mimeExtensions = self::MIME_TO_CLIENT_EXTENSIONS[$mimeType] ?? [];

        if (
            $extension === ''
            || !in_array($extension, $configuredExtensions, true)
            || !in_array($extension, $mimeExtensions, true)
        ) {
            throw new InvalidArgumentException(
                'Uploaded file is not a supported image.',
            );
        }
    }

    private function mimeTypeIsConfigured(string $mimeType): bool
    {
        $configuredExtensions = $this->config->getAllowedImageExtensions();

        foreach (self::MIME_TO_CLIENT_EXTENSIONS[$mimeType] ?? [] as $extension) {
            if (in_array($extension, $configuredExtensions, true)) {
                return true;
            }
        }

        return false;
    }

    private function ensureQuizDirectory(int $quizId): string
    {
        $storageRoot = $this->ensureStorageRoot();
        $quizDirectory = $storageRoot
            . DIRECTORY_SEPARATOR . (string) $quizId;

        if (is_link($quizDirectory)) {
            throw new RuntimeException(
                'Question image quiz directory is invalid.',
            );
        }

        if (
            !is_dir($quizDirectory)
            && !@mkdir($quizDirectory, 0750)
            && !is_dir($quizDirectory)
        ) {
            throw new RuntimeException(
                'Question image quiz directory could not be created.',
            );
        }

        return $quizDirectory;
    }

    private function ensureStorageRoot(): string
    {
        $configuredRoot = $this->config->getQuestionImageStoragePath();

        if (is_link($configuredRoot)) {
            throw new RuntimeException(
                'Question image storage directory is invalid.',
            );
        }

        if (
            !is_dir($configuredRoot)
            && !@mkdir($configuredRoot, 0750, true)
            && !is_dir($configuredRoot)
        ) {
            throw new RuntimeException(
                'Question image storage directory could not be created.',
            );
        }

        $resolvedRoot = realpath($configuredRoot);

        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            throw new RuntimeException(
                'Question image storage directory is invalid.',
            );
        }

        return rtrim($resolvedRoot, DIRECTORY_SEPARATOR);
    }

    private function physicalPath(QuestionImagePath $managedPath): string
    {
        $configuredRoot = $this->config->getQuestionImageStoragePath();

        if (is_link($configuredRoot)) {
            throw new RuntimeException(
                'Question image storage directory is invalid.',
            );
        }

        $resolvedRoot = realpath($configuredRoot);
        $storageRoot = is_string($resolvedRoot)
            ? $resolvedRoot
            : $configuredRoot;

        return rtrim($storageRoot, '/\\')
            . DIRECTORY_SEPARATOR . (string) $managedPath->quizId
            . DIRECTORY_SEPARATOR . $managedPath->fileName;
    }

    private function isStoredFile(QuestionImagePath $managedPath): bool
    {
        $quizDirectory = dirname($this->physicalPath($managedPath));
        $physicalPath = $this->physicalPath($managedPath);

        return is_dir($quizDirectory)
            && !is_link($quizDirectory)
            && is_file($physicalPath)
            && !is_link($physicalPath)
            && is_readable($physicalPath);
    }

    private function directoryIsEmpty(string $directory): bool
    {
        $entries = @scandir($directory);

        return $entries === ['.', '..'];
    }

    private function throwTooLarge(): never
    {
        throw new QuestionImageUploadTooLargeException(sprintf(
            'Question image cannot exceed %d MB.',
            $this->config->getMaximumUploadSizeMb(),
        ));
    }

    private function ensurePositiveQuizId(int $quizId): void
    {
        if ($quizId < 1) {
            throw new InvalidArgumentException(
                'Quiz identifier must be a positive integer.',
            );
        }
    }
}
