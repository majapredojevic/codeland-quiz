<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use CodeLandQuiz\DTO\ParticipantConnectionResultDTO;
use CodeLandQuiz\DTO\PublicSessionQuestionDTO;
use CodeLandQuiz\DTO\PublicSessionQuestionOptionDTO;
use CodeLandQuiz\DTO\StartQuizSessionResultDTO;
use DateTimeImmutable;

final class SessionWebSocketPayloadMapper
{
    /**
     * @return array<string, mixed>
     */
    public function gameStarted(StartQuizSessionResultDTO $result): array
    {
        return [
            'session' => [
                'id' => $result->session->id,
                'status' => $result->session->status->value,
                'startedAt' => $this->formatDateTime(
                    $result->session->startedAt,
                ),
                'questionCount' => $result->questionCount,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function questionStarted(StartQuizSessionResultDTO $result): array
    {
        return [
            'question' => $this->question(
                question: $result->currentQuestion,
                questionCount: $result->questionCount,
            ),
            'timing' => [
                'startedAt' => $this->formatDateTime(
                    $result->session->currentQuestionStartedAt,
                ),
                'answerDeadline' => $this->formatDateTime(
                    $result->session->currentQuestionDeadline,
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function participantGameStarted(
        ParticipantConnectionResultDTO $result,
    ): array {
        return [
            'session' => [
                'id' => $result->sessionId,
                'status' => $result->sessionStatus->value,
                'startedAt' => $this->formatDateTime(
                    $result->currentQuestionStartedAt,
                ),
                'questionCount' => $result->questionCount,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function participantQuestionStarted(
        ParticipantConnectionResultDTO $result,
    ): array {
        return [
            'question' => $result->currentQuestion === null
                ? null
                : $this->question(
                    question: $result->currentQuestion,
                    questionCount: $result->questionCount,
                ),
            'timing' => [
                'startedAt' => $this->formatDateTime(
                    $result->currentQuestionStartedAt,
                ),
                'answerDeadline' => $this->formatDateTime(
                    $result->currentQuestionDeadline,
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function question(
        PublicSessionQuestionDTO $question,
        int $questionCount,
    ): array
    {
        return [
            'id' => $question->id,
            'questionText' => $question->questionText,
            'questionType' => $question->questionType->value,
            'imagePath' => $question->imagePath,
            'timeLimitSeconds' => $question->timeLimitSeconds,
            'maxPoints' => $question->maxPoints,
            'questionOrder' => $question->questionOrder,
            'questionCount' => $questionCount,
            'options' => array_map(
                $this->option(...),
                $question->options,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function option(PublicSessionQuestionOptionDTO $option): array
    {
        return [
            'id' => $option->id,
            'optionText' => $option->optionText,
            'optionOrder' => $option->optionOrder,
        ];
    }

    private function formatDateTime(?DateTimeImmutable $dateTime): ?string
    {
        if ($dateTime === null) {
            return null;
        }

        return $dateTime->format(DATE_ATOM);
    }
}
