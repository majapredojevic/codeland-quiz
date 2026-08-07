<?php

declare(strict_types=1);

namespace CodeLandQuiz\WebSocket;

use CodeLandQuiz\DTO\FinalQuizSessionResultDTO;

final readonly class FinishedSessionWebSocketNotifier
{
    public function __construct(
        private SessionWebSocketBroadcaster $sessionBroadcaster,
        private ParticipantWebSocketSender $participantSender,
        private SessionWebSocketPayloadMapper $payloadMapper,
    ) {
    }

    public function notify(FinalQuizSessionResultDTO $result): void
    {
        $this->sessionBroadcaster->broadcast(
            sessionId: $result->session->id,
            type: 'GAME_FINISHED',
            payload: $this->payloadMapper->gameFinished($result),
        );

        foreach ($result->leaderboard as $entry) {
            $this->participantSender->send(
                participantId: $entry->participantId,
                type: 'FINAL_RESULT',
                payload: $this->payloadMapper->finalParticipantResult($entry),
            );
        }
    }
}
