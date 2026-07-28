<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\DTO\QuizItemDTO;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Quiz\Exception\QuizCannotBeActivatedException;
use CodeLandQuiz\Quiz\Exception\QuizHasOpenSessionsException;
use CodeLandQuiz\Quiz\Exception\QuizNotFoundException;
use CodeLandQuiz\Quiz\Exception\QuizStatusLockedException;
use CodeLandQuiz\Quiz\Exception\QuizTitleVersionAlreadyExistsException;
use CodeLandQuiz\Quiz\Http\CreateQuizRequest;
use CodeLandQuiz\Quiz\Http\ListQuizzesRequest;
use CodeLandQuiz\Quiz\Http\UpdateQuizRequest;
use CodeLandQuiz\Quiz\QuizService;
use CodeLandQuiz\Topic\Exception\TopicNotFoundException;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Throwable;

final class QuizController
{
    public function __construct(
        private readonly QuizService $quizService,
        private readonly ResponseFactory $responseFactory,
        private readonly AppConfig $config,
    ) {
    }

    public function list(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $dto = ListQuizzesRequest::from(
                $request,
                $this->config,
            );
            $result = $this->quizService->listQuizzes($dto);

            $this->responseFactory->json($response, [
                'quizzes' => array_map(
                    fn (QuizItemDTO $quiz): array => $this->quizResponse($quiz),
                    $result->quizzes,
                ),
                'pagination' => [
                    'pageIndex' => $result->pageIndex,
                    'pageSize' => $result->pageSize,
                    'totalItems' => $result->totalItems,
                    'totalPages' => $result->totalPages,
                ],
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (Throwable) {
            $this->responseFactory->error(
                $response,
                'Internal server error.',
                500,
            );
        }
    }

    public function get(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $quizId = $context->getRouteInt('id');
            $quiz = $this->quizService->getQuiz($quizId);

            $this->responseFactory->json($response, [
                'quiz' => $this->quizResponse($quiz),
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (QuizNotFoundException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                404,
            );
        } catch (Throwable) {
            $this->responseFactory->error(
                $response,
                'Internal server error.',
                500,
            );
        }
    }

    public function create(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $dto = CreateQuizRequest::from($request);
            $actorUserId = $context->getCurrentUser()->id;
            $quiz = $this->quizService->createQuiz(
                actorUserId: $actorUserId,
                dto: $dto,
            );

            $this->responseFactory->json($response, [
                'quiz' => $this->quizResponse($quiz),
            ], 201);
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (TopicNotFoundException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                404,
            );
        } catch (QuizTitleVersionAlreadyExistsException) {
            $this->responseFactory->error(
                $response,
                'A quiz with this title and version already exists.',
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

    public function update(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $quizId = $context->getRouteInt('id');
            $dto = UpdateQuizRequest::from($request);
            $actorUserId = $context->getCurrentUser()->id;
            $quiz = $this->quizService->updateQuiz(
                actorUserId: $actorUserId,
                quizId: $quizId,
                dto: $dto,
            );

            $this->responseFactory->json($response, [
                'quiz' => $this->quizResponse($quiz),
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (QuizNotFoundException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                404,
            );
        } catch (TopicNotFoundException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                404,
            );
        } catch (QuizTitleVersionAlreadyExistsException) {
            $this->responseFactory->error(
                $response,
                'A quiz with this title and version already exists.',
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

    public function delete(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $quizId = $context->getRouteInt('id');
            $actorUserId = $context->getCurrentUser()->id;

            $this->quizService->deleteQuiz($actorUserId, $quizId);

            $response->status(204);
            $response->end();
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (QuizNotFoundException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                404,
            );
        } catch (QuizHasOpenSessionsException) {
            $this->responseFactory->error(
                $response,
                'Quiz cannot be deleted while it has an open session.',
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

    public function activate(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $quizId = $context->getRouteInt('id');
            $actorUserId = $context->getCurrentUser()->id;
            $quiz = $this->quizService->activateQuiz($actorUserId, $quizId);

            $this->responseFactory->json($response, [
                'quiz' => $this->quizResponse($quiz),
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (QuizNotFoundException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                404,
            );
        } catch (QuizCannotBeActivatedException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                409,
            );
        } catch (QuizStatusLockedException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
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

    public function deactivate(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $quizId = $context->getRouteInt('id');
            $actorUserId = $context->getCurrentUser()->id;
            $quiz = $this->quizService->deactivateQuiz($actorUserId, $quizId);

            $this->responseFactory->json($response, [
                'quiz' => $this->quizResponse($quiz),
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (QuizNotFoundException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                404,
            );
        } catch (QuizStatusLockedException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
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

    /**
     * @return array<string, mixed>
     */
    private function quizResponse(QuizItemDTO $quiz): array
    {
        return [
            'id' => $quiz->id,
            'title' => $quiz->title,
            'version' => $quiz->version,
            'description' => $quiz->description,
            'isActive' => $quiz->isActive,
            'questionCount' => $quiz->questionCount,
            'topic' => $quiz->topicId === null
                ? null
                : [
                    'id' => $quiz->topicId,
                    'name' => $quiz->topicName,
                ],
            'createdBy' => [
                'id' => $quiz->createdById,
                'name' => $quiz->createdByName,
            ],
            'updatedBy' => [
                'id' => $quiz->updatedById,
                'name' => $quiz->updatedByName,
            ],
            'createdAt' => $quiz->createdAt->format(DATE_ATOM),
            'updatedAt' => $quiz->updatedAt->format(DATE_ATOM),
        ];
    }
}
