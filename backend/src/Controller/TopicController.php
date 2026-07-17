<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\DTO\TopicItemDTO;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Topic\Exception\TopicNotFoundException;
use CodeLandQuiz\Topic\Http\ListTopicsRequest;
use CodeLandQuiz\Topic\TopicService;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Throwable;

final class TopicController
{
    public function __construct(
        private readonly TopicService $topicService,
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
            $dto = ListTopicsRequest::from(
                $request,
                $this->config,
            );
            $result = $this->topicService->listTopics($dto);

            $this->responseFactory->json($response, [
                'topics' => array_map(
                    fn (TopicItemDTO $topic): array => $this->topicResponse($topic),
                    $result->topics,
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
            $topicId = $context->getRouteInt('id');
            $topic = $this->topicService->getTopic($topicId);

            $this->responseFactory->json($response, [
                'topic' => $this->topicResponse($topic),
            ]);
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
    private function topicResponse(TopicItemDTO $topic): array
    {
        return [
            'id' => $topic->id,
            'name' => $topic->name,
            'description' => $topic->description,
            'quizCount' => $topic->quizCount,
            'createdBy' => [
                'id' => $topic->createdById,
                'name' => $topic->createdByName,
            ],
            'updatedBy' => [
                'id' => $topic->updatedById,
                'name' => $topic->updatedByName,
            ],
            'createdAt' => $topic->createdAt->format(DATE_ATOM),
            'updatedAt' => $topic->updatedAt->format(DATE_ATOM),
        ];
    }
}
