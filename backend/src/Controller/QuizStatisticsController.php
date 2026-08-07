<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\DTO\QuizQuestionStatisticsDTO;
use CodeLandQuiz\DTO\QuizStatisticsDTO;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Quiz\Exception\QuizNotFoundException;
use CodeLandQuiz\Quiz\QuizStatisticsService;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Throwable;

final class QuizStatisticsController
{
    public function __construct(
        private readonly QuizStatisticsService $statisticsService,
        private readonly ResponseFactory $responseFactory,
    ) {
    }

    public function get(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $quizId = $context->getRouteInt('id');
            $statistics = $this->statisticsService->getStatistics($quizId);

            $this->responseFactory->json(
                $response,
                $this->statisticsResponse($statistics),
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
    private function statisticsResponse(QuizStatisticsDTO $statistics): array
    {
        $summary = $statistics->summary;

        return [
            'quiz' => [
                'id' => $statistics->quizId,
                'title' => $statistics->quizTitle,
                'version' => $statistics->quizVersion,
            ],
            'summary' => [
                'finishedSessionCount' => $summary->finishedSessionCount,
                'participantEntryCount' => $summary->participantEntryCount,
                'registeredParticipationCount' =>
                    $summary->registeredParticipationCount,
                'guestParticipationCount' =>
                    $summary->guestParticipationCount,
                'uniqueRegisteredStudentCount' =>
                    $summary->uniqueRegisteredStudentCount,
                'averageParticipantsPerSession' =>
                    $summary->averageParticipantsPerSession,
                'totalPossibleAnswerCount' =>
                    $summary->totalPossibleAnswerCount,
                'answerCount' => $summary->answerCount,
                'correctAnswerCount' => $summary->correctAnswerCount,
                'incorrectAnswerCount' => $summary->incorrectAnswerCount,
                'unansweredCount' => $summary->unansweredCount,
                'accuracyPercentage' => $summary->accuracyPercentage,
                'answerRatePercentage' => $summary->answerRatePercentage,
                'highestScore' => $summary->highestScore,
                'averageScore' => $summary->averageScore,
            ],
            'questions' => array_map(
                $this->questionResponse(...),
                $statistics->questions,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function questionResponse(
        QuizQuestionStatisticsDTO $question,
    ): array {
        return [
            'sourceQuestionId' => $question->sourceQuestionId,
            'questionText' => $question->questionText,
            'questionType' => $question->questionType->value,
            'latestQuestionOrder' => $question->latestQuestionOrder,
            'isCurrentlyDeleted' => $question->isCurrentlyDeleted,
            'sessionCount' => $question->sessionCount,
            'participantOpportunityCount' =>
                $question->participantOpportunityCount,
            'answerCount' => $question->answerCount,
            'correctAnswerCount' => $question->correctAnswerCount,
            'incorrectAnswerCount' => $question->incorrectAnswerCount,
            'unansweredCount' => $question->unansweredCount,
            'accuracyPercentage' => $question->accuracyPercentage,
            'answerRatePercentage' => $question->answerRatePercentage,
            'averageResponseTimeMs' => $question->averageResponseTimeMs,
            'averagePointsAwarded' => $question->averagePointsAwarded,
        ];
    }
}
