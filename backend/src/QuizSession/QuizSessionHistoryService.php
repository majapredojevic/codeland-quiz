<?php

declare(strict_types=1);

namespace CodeLandQuiz\QuizSession;

use CodeLandQuiz\DTO\QuizSessionHistoryItemDTO;
use CodeLandQuiz\DTO\QuizSessionHistoryPageDTO;
use CodeLandQuiz\DTO\QuizSessionHistoryQueryDTO;
use CodeLandQuiz\DTO\QuizSessionItemDTO;
use CodeLandQuiz\DTO\QuizSessionReportDTO;
use CodeLandQuiz\Model\QuizSessionHistoryOverview;
use CodeLandQuiz\Model\QuizSessionOverview;
use CodeLandQuiz\QuizSession\Exception\QuizSessionNotFoundException;
use CodeLandQuiz\Repository\QuizSessionHistoryRepository;
use CodeLandQuiz\Repository\QuizSessionRepository;

final readonly class QuizSessionHistoryService
{
    public function __construct(
        private QuizSessionHistoryRepository $history,
        private QuizSessionRepository $sessions,
        private QuizSessionReportAssembler $reportAssembler,
    ) {
    }

    public function listSessions(
        QuizSessionHistoryQueryDTO $query,
    ): QuizSessionHistoryPageDTO {
        $totalItems = $this->history->count($query);
        $sessions = $this->history->findPage($query);
        $totalPages = $totalItems === 0
            ? 0
            : (int) ceil($totalItems / $query->pageSize);

        return new QuizSessionHistoryPageDTO(
            items: array_map(
                fn (QuizSessionHistoryOverview $session): QuizSessionHistoryItemDTO =>
                    $this->toItem($session),
                $sessions,
            ),
            pageIndex: $query->pageIndex,
            pageSize: $query->pageSize,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }

    public function getSessionReport(
        int $sessionId,
    ): QuizSessionReportDTO {
        $session = $this->sessions->findOverviewById($sessionId);

        if ($session === null) {
            throw new QuizSessionNotFoundException(
                'Quiz session was not found.',
            );
        }

        return $this->reportAssembler->assemble(
            $this->toSessionItem($session),
        );
    }

    private function toItem(
        QuizSessionHistoryOverview $session,
    ): QuizSessionHistoryItemDTO {
        return new QuizSessionHistoryItemDTO(
            id: $session->id,
            quizId: $session->quizId,
            quizTitle: $session->quizTitle,
            quizVersion: $session->quizVersion,
            hostUserId: $session->hostUserId,
            hostUserName: $session->hostUserName,
            gamePin: $session->gamePin,
            status: $session->status,
            questionCount: $session->questionCount,
            participantCount: $session->participantCount,
            removedParticipantCount: $session->removedParticipantCount,
            startedAt: $session->startedAt,
            endedAt: $session->endedAt,
            createdAt: $session->createdAt,
        );
    }

    private function toSessionItem(
        QuizSessionOverview $session,
    ): QuizSessionItemDTO {
        return new QuizSessionItemDTO(
            id: $session->id,
            quizId: $session->quizId,
            hostUserId: $session->hostUserId,
            hostUserName: $session->hostUserName,
            quizTitle: $session->quizTitle,
            quizVersion: $session->quizVersion,
            gamePin: $session->gamePin,
            status: $session->status,
            currentQuestionOrder: $session->currentQuestionOrder,
            currentQuestionStartedAt: $session->currentQuestionStartedAt,
            currentQuestionDeadline: $session->currentQuestionDeadline,
            currentQuestionClosedAt: $session->currentQuestionClosedAt,
            joinDeadline: $session->joinDeadline,
            startedAt: $session->startedAt,
            endedAt: $session->endedAt,
            createdAt: $session->createdAt,
            questionCount: $session->questionCount,
            participantCount: $session->participantCount,
        );
    }
}
