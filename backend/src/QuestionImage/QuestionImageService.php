<?php

declare(strict_types=1);

namespace CodeLandQuiz\QuestionImage;

use CodeLandQuiz\Question\Exception\QuizContentLockedException;
use CodeLandQuiz\QuestionImage\Exception\QuestionImageReferencedException;
use CodeLandQuiz\Quiz\Exception\QuizNotFoundException;
use CodeLandQuiz\Repository\QuestionImageReferenceRepository;
use CodeLandQuiz\Repository\QuizRepository;
use CodeLandQuiz\Support\TransactionManager;
use Throwable;

final readonly class QuestionImageService
{
    public function __construct(
        private QuizRepository $quizzes,
        private QuestionImageReferenceRepository $references,
        private QuestionImageStorage $storage,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @param array<string, mixed> $uploadedFile
     */
    public function upload(
        int $quizId,
        array $uploadedFile,
    ): QuestionImageAsset {
        $storedImage = null;

        try {
            return $this->transactionManager->transactional(
                function () use (
                    $quizId,
                    $uploadedFile,
                    &$storedImage,
                ): QuestionImageAsset {
                    $this->lockEditableQuiz($quizId);

                    $storedImage = $this->storage->store(
                        $quizId,
                        $uploadedFile,
                    );

                    return $storedImage;
                },
            );
        } catch (Throwable $throwable) {
            if ($storedImage !== null) {
                try {
                    $this->storage->delete(
                        $quizId,
                        $storedImage->fileName,
                    );
                } catch (Throwable) {
                    // Preserve the original upload/transaction failure.
                }
            }

            throw $throwable;
        }
    }

    public function cleanup(
        int $quizId,
        string $fileName,
    ): void {
        $this->transactionManager->transactional(
            function () use ($fileName, $quizId): void {
                if ($this->quizzes->findByIdForUpdate($quizId) === null) {
                    throw new QuizNotFoundException('Quiz was not found.');
                }

                $managedPath = QuestionImagePath::fromFileName(
                    $quizId,
                    $fileName,
                );

                $this->storage->publicFile($quizId, $fileName);

                if ($this->references->isReferenced($managedPath->toMediaPath())) {
                    throw new QuestionImageReferencedException(
                        'Question image is still referenced and cannot be removed.',
                    );
                }

                $this->storage->delete($quizId, $fileName);
            },
        );
    }

    private function lockEditableQuiz(int $quizId): void
    {
        if ($this->quizzes->findByIdForUpdate($quizId) === null) {
            throw new QuizNotFoundException('Quiz was not found.');
        }

        if ($this->quizzes->hasOpenSessions($quizId)) {
            throw new QuizContentLockedException(
                'Quiz content cannot be changed while it has an open session.',
            );
        }
    }
}
