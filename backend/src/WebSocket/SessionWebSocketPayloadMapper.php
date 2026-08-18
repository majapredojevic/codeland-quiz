<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use CodeLandQuiz\DTO\ClosedSessionQuestionStateDTO;
use CodeLandQuiz\DTO\FinalQuizSessionResultDTO;
use CodeLandQuiz\DTO\FinalSessionLeaderboardEntryDTO;
use CodeLandQuiz\DTO\ParticipantConnectionResultDTO;
use CodeLandQuiz\DTO\PublicSessionQuestionDTO;
use CodeLandQuiz\DTO\PublicSessionQuestionOptionDTO;
use CodeLandQuiz\DTO\SessionLeaderboardEntryDTO;
use CodeLandQuiz\DTO\SessionQuestionParticipantResultDTO;
use CodeLandQuiz\DTO\StartNextSessionQuestionResultDTO;
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
        return $this->questionStartedPayload(
            question: $result->currentQuestion,
            questionCount: $result->questionCount,
            startedAt: $result->session->currentQuestionStartedAt,
            answerDeadline: $result->session->currentQuestionDeadline,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function nextQuestionStarted(
        StartNextSessionQuestionResultDTO $result,
    ): array {
        return $this->questionStartedPayload(
            question: $result->currentQuestion,
            questionCount: $result->questionCount,
            startedAt: $result->session->currentQuestionStartedAt,
            answerDeadline: $result->session->currentQuestionDeadline,
        );
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
        $payload = $this->questionStartedPayload(
            question: $result->currentQuestion,
            questionCount: $result->questionCount,
            startedAt: $result->currentQuestionStartedAt,
            answerDeadline: $result->currentQuestionDeadline,
        );

        $payload['participantAnswer'] = [
            'answered' => $result->currentQuestionSelectedOptionIds !== [],
            'selectedOptionIds' =>
                $result->currentQuestionSelectedOptionIds,
        ];

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function questionClosed(
        ClosedSessionQuestionStateDTO $state,
    ): array {
        return [
            'questionOrder' => $state->question->questionOrder,
            'closedAt' => $this->formatDateTime($state->closedAt),
            'correctOptionIds' => $state->correctOptionIds,
            'stats' => [
                'participantCount' => $state->stats->participantCount,
                'answerCount' => $state->stats->answerCount,
                'correctAnswerCount' => $state->stats->correctAnswerCount,
                'incorrectAnswerCount' => $state->stats->incorrectAnswerCount,
                'unansweredCount' => $state->stats->unansweredCount,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function participantQuestionClosed(
        ParticipantConnectionResultDTO $result,
    ): array {
        if ($result->closedQuestion === null) {
            return [];
        }

        $payload = $this->questionClosed($result->closedQuestion);
        $payload['question'] = $this->question(
            question: $result->closedQuestion->question,
            questionCount: $result->questionCount,
        );

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function answerResult(
        SessionQuestionParticipantResultDTO $result,
        int $questionOrder,
    ): array {
        return [
            'questionOrder' => $questionOrder,
            'answered' => $result->answered,
            'selectedOptionIds' => $result->selectedOptionIds,
            'isCorrect' => $result->isCorrect,
            'responseTimeMs' => $result->responseTimeMs,
            'pointsAwarded' => $result->pointsAwarded,
            'totalScore' => $result->totalScore,
            'answeredAt' => $this->formatDateTime($result->answeredAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function leaderboardUpdated(
        ClosedSessionQuestionStateDTO $state,
    ): array {
        return [
            'questionOrder' => $state->question->questionOrder,
            'participantCount' => $state->stats->participantCount,
            'entries' => array_map(
                static fn(SessionLeaderboardEntryDTO $entry): array => [
                    'rank' => $entry->rank,
                    'participantId' => $entry->participantId,
                    'participantType' => $entry->participantType->value,
                    'nickname' => $entry->nickname,
                    'avatarKey' => $entry->avatarKey,
                    'totalScore' => $entry->totalScore,
                    'pointsAwardedThisQuestion' =>
                        $entry->pointsAwardedThisQuestion,
                ],
                $state->leaderboard,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function gameFinished(FinalQuizSessionResultDTO $result): array
    {
        return [
            'session' => [
                'id' => $result->session->id,
                'status' => $result->session->status->value,
                'endedAt' => $this->formatDateTime(
                    $result->session->endedAt,
                ),
                'participantCount' => $result->participantCount,
                'totalAnswerCount' => $result->totalAnswerCount,
                'totalCorrectAnswerCount' =>
                    $result->totalCorrectAnswerCount,
            ],
            'topThree' => array_map(
                $this->finalParticipantResult(...),
                $result->topThree,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function finalParticipantResult(
        FinalSessionLeaderboardEntryDTO $entry,
    ): array {
        return [
            'rank' => $entry->rank,
            'participantId' => $entry->participantId,
            'participantType' => $entry->participantType->value,
            'nickname' => $entry->nickname,
            'avatarKey' => $entry->avatarKey,
            'totalScore' => $entry->totalScore,
            'answerCount' => $entry->answerCount,
            'correctAnswerCount' => $entry->correctAnswerCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function questionStartedPayload(
        ?PublicSessionQuestionDTO $question,
        int $questionCount,
        ?DateTimeImmutable $startedAt,
        ?DateTimeImmutable $answerDeadline,
    ): array {
        return [
            'question' => $question === null
                ? null
                : $this->question(
                    question: $question,
                    questionCount: $questionCount,
                ),
            'timing' => [
                'startedAt' => $this->formatDateTime($startedAt),
                'answerDeadline' => $this->formatDateTime($answerDeadline),
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
