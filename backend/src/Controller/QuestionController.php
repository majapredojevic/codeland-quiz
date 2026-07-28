<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\DTO\QuestionItemDTO;
use CodeLandQuiz\DTO\QuestionOptionItemDTO;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Question\Exception\QuizContentLockedException;
use CodeLandQuiz\Question\Exception\QuestionNotFoundException;
use CodeLandQuiz\Question\Http\CreateQuestionRequest;
use CodeLandQuiz\Question\Http\UpdateQuestionRequest;
use CodeLandQuiz\Question\QuestionService;
use CodeLandQuiz\Quiz\Exception\QuizNotFoundException;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Throwable;

final class QuestionController
{
    public function __construct(
        private readonly QuestionService $questionService,
        private readonly ResponseFactory $responseFactory,
    ) {
    }

    public function list(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $quizId = $context->getRouteInt('quizId');
            $questions = $this->questionService->listQuestions($quizId);

            $this->responseFactory->json($response, [
                'questions' => array_map(
                    fn (QuestionItemDTO $question): array =>
                        $this->questionResponse($question),
                    $questions,
                ),
                'questionCount' => count($questions),
            ]);
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
            $quizId = $context->getRouteInt('quizId');
            $questionId = $context->getRouteInt('questionId');
            $question = $this->questionService->getQuestion(
                $quizId,
                $questionId,
            );

            $this->responseFactory->json($response, [
                'question' => $this->questionResponse($question),
            ]);
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
        } catch (QuestionNotFoundException) {
            $this->responseFactory->error(
                $response,
                'Question was not found.',
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
            $quizId = $context->getRouteInt('quizId');
            $dto = CreateQuestionRequest::from($request);
            $actorUserId = $context->getCurrentUser()->id;
            $question = $this->questionService->createQuestion(
                actorUserId: $actorUserId,
                quizId: $quizId,
                dto: $dto,
            );

            $this->responseFactory->json($response, [
                'question' => $this->questionResponse($question),
            ], 201);
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

    public function update(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $quizId = $context->getRouteInt('quizId');
            $questionId = $context->getRouteInt('questionId');
            $dto = UpdateQuestionRequest::from($request);
            $actorUserId = $context->getCurrentUser()->id;
            $question = $this->questionService->updateQuestion(
                actorUserId: $actorUserId,
                quizId: $quizId,
                questionId: $questionId,
                dto: $dto,
            );

            $this->responseFactory->json($response, [
                'question' => $this->questionResponse($question),
            ]);
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
        } catch (QuestionNotFoundException) {
            $this->responseFactory->error(
                $response,
                'Question was not found.',
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

    public function delete(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $quizId = $context->getRouteInt('quizId');
            $questionId = $context->getRouteInt('questionId');
            $actorUserId = $context->getCurrentUser()->id;

            $this->questionService->deleteQuestion(
                $actorUserId,
                $quizId,
                $questionId,
            );

            $response->status(204);
            $response->end();
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
        } catch (QuestionNotFoundException) {
            $this->responseFactory->error(
                $response,
                'Question was not found.',
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

    /**
     * @return array<string, mixed>
     */
    private function questionResponse(QuestionItemDTO $question): array
    {
        return [
            'id' => $question->id,
            'quizId' => $question->quizId,
            'questionText' => $question->questionText,
            'questionType' => $question->questionType->value,
            'imagePath' => $question->imagePath,
            'timeLimitSeconds' => $question->timeLimitSeconds,
            'maxPoints' => $question->maxPoints,
            'questionOrder' => $question->questionOrder,
            'options' => array_map(
                fn (QuestionOptionItemDTO $option): array =>
                    $this->optionResponse($option),
                $question->options,
            ),
            'createdAt' => $question->createdAt->format(DATE_ATOM),
            'updatedAt' => $question->updatedAt->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function optionResponse(QuestionOptionItemDTO $option): array
    {
        return [
            'id' => $option->id,
            'optionText' => $option->optionText,
            'isCorrect' => $option->isCorrect,
            'optionOrder' => $option->optionOrder,
        ];
    }
}
