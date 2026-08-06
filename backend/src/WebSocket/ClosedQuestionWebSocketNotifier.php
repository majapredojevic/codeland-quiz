<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use CodeLandQuiz\DTO\ClosedSessionQuestionStateDTO;

final readonly class ClosedQuestionWebSocketNotifier
{
    public function __construct(
        private SessionWebSocketBroadcaster $sessionBroadcaster,
        private ParticipantWebSocketSender $participantSender,
        private SessionWebSocketPayloadMapper $payloadMapper,
    ) {
    }

    public function notify(
        int $sessionId,
        ClosedSessionQuestionStateDTO $state,
    ): void {
        $this->sessionBroadcaster->broadcast(
            sessionId: $sessionId,
            type: 'QUESTION_CLOSED',
            payload: $this->payloadMapper->questionClosed($state),
        );

        foreach ($state->participantResults as $participantResult) {
            $this->participantSender->send(
                participantId: $participantResult->participantId,
                type: 'ANSWER_RESULT',
                payload: $this->payloadMapper->answerResult(
                    result: $participantResult,
                    questionOrder: $state->question->questionOrder,
                ),
            );
        }

        $this->sessionBroadcaster->broadcast(
            sessionId: $sessionId,
            type: 'LEADERBOARD_UPDATED',
            payload: $this->payloadMapper->leaderboardUpdated($state),
        );
    }
}
