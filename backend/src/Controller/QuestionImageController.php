<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Question\Exception\QuizContentLockedException;
use CodeLandQuiz\QuestionImage\Exception\QuestionImageNotFoundException;
use CodeLandQuiz\QuestionImage\Exception\QuestionImageReferencedException;
use CodeLandQuiz\QuestionImage\Exception\QuestionImageUploadTooLargeException;
use CodeLandQuiz\QuestionImage\QuestionImageService;
use CodeLandQuiz\QuestionImage\QuestionImageStorage;
use CodeLandQuiz\Quiz\Exception\QuizNotFoundException;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Throwable;

final readonly class QuestionImageController
{
    public function __construct(
        private QuestionImageService $questionImageService,
        private QuestionImageStorage $questionImageStorage,
        private ResponseFactory $responseFactory,
    ) {
    }

    public function upload(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $quizId = $context->getRouteInt('quizId');
            $uploadedFile = $request->files['image'] ?? null;

            if (!is_array($uploadedFile)) {
                throw new InvalidArgumentException(
                    'Question image upload is malformed.',
                );
            }

            $image = $this->questionImageService->upload(
                $quizId,
                $uploadedFile,
            );

            $this->responseFactory->json($response, [
                'image' => [
                    'fileName' => $image->fileName,
                    'path' => $image->path,
                ],
            ], 201);
        } catch (QuestionImageUploadTooLargeException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                413,
            );
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (QuizNotFoundException) {
            $this->responseFactory->error(
                $response,
                'Quiz was not found.',
                404,
            );
        } catch (QuizContentLockedException) {
            $this->responseFactory->error(
                $response,
                'Quiz content cannot be changed while it has an open session.',
                409,
            );
        } catch (Throwable) {
            $this->responseFactory->error(
                $response,
                'Internal server error.',
                500,
            );
        }
    }

    public function cleanup(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $quizId = $context->getRouteInt('quizId');
            $fileName = $context->getRouteParameter('fileName');

            $this->questionImageService->cleanup($quizId, $fileName);

            $this->responseFactory->noContent($response);
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (QuizNotFoundException) {
            $this->responseFactory->error(
                $response,
                'Quiz was not found.',
                404,
            );
        } catch (QuestionImageNotFoundException) {
            $this->responseFactory->error(
                $response,
                'Question image was not found.',
                404,
            );
        } catch (QuestionImageReferencedException) {
            $this->responseFactory->error(
                $response,
                'Question image is still referenced and cannot be removed.',
                409,
            );
        } catch (Throwable) {
            $this->responseFactory->error(
                $response,
                'Internal server error.',
                500,
            );
        }
    }

    public function media(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        $response->header('X-Content-Type-Options', 'nosniff');

        try {
            $quizId = $context->getRouteInt('quizId');
            $fileName = $context->getRouteParameter('fileName');
            $image = $this->questionImageStorage->publicFile(
                $quizId,
                $fileName,
            );

            $response->header('Content-Type', $image->contentType);
            $response->header(
                'Cache-Control',
                'public, max-age=31536000, immutable',
            );

            if (!$response->sendfile($image->physicalPath)) {
                $response->header('Cache-Control', 'no-store');
                $this->responseFactory->error(
                    $response,
                    'Question image was not found.',
                    404,
                );
            }
        } catch (InvalidArgumentException | QuestionImageNotFoundException) {
            $this->responseFactory->error(
                $response,
                'Question image was not found.',
                404,
            );
        } catch (Throwable) {
            $response->header('Cache-Control', 'no-store');
            $this->responseFactory->error(
                $response,
                'Internal server error.',
                500,
            );
        }
    }
}
