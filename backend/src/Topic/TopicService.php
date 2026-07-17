<?php

declare(strict_types=1);

namespace CodeLandQuiz\Topic;

use CodeLandQuiz\DTO\ListTopicsDTO;
use CodeLandQuiz\DTO\TopicItemDTO;
use CodeLandQuiz\DTO\TopicListResultDTO;
use CodeLandQuiz\Model\TopicOverview;
use CodeLandQuiz\Repository\TopicRepository;
use CodeLandQuiz\Topic\Exception\TopicNotFoundException;

final readonly class TopicService
{
    public function __construct(
        private TopicRepository $topics,
    ) {
    }

    public function listTopics(
        ListTopicsDTO $dto,
    ): TopicListResultDTO {
        $totalItems = $this->topics->count($dto->search);
        $topics = $this->topics->findPage(
            $dto->pageSize,
            $dto->getOffset(),
            $dto->search,
            $dto->sort,
        );
        $totalPages = $totalItems === 0
            ? 0
            : (int) ceil($totalItems / $dto->pageSize);

        return new TopicListResultDTO(
            topics: array_map(
                fn (TopicOverview $topic): TopicItemDTO => $this->toTopicItem($topic),
                $topics,
            ),
            pageIndex: $dto->pageIndex,
            pageSize: $dto->pageSize,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }

    public function getTopic(int $id): TopicItemDTO
    {
        $topic = $this->topics->findOverviewById($id);

        if ($topic === null) {
            throw new TopicNotFoundException('Topic was not found.');
        }

        return $this->toTopicItem($topic);
    }

    private function toTopicItem(
        TopicOverview $topic,
    ): TopicItemDTO {
        return new TopicItemDTO(
            id: $topic->id,
            name: $topic->name,
            description: $topic->description,
            quizCount: $topic->quizCount,
            createdById: $topic->createdById,
            createdByName: $topic->createdByName,
            updatedById: $topic->updatedById,
            updatedByName: $topic->updatedByName,
            createdAt: $topic->createdAt,
            updatedAt: $topic->updatedAt,
        );
    }
}
