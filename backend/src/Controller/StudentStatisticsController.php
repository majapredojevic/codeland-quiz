<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\DTO\StudentItemDTO;
use CodeLandQuiz\DTO\StudentQuizStatisticsDTO;
use CodeLandQuiz\DTO\StudentSessionPerformanceDTO;
use CodeLandQuiz\DTO\StudentSessionPerformancePageDTO;
use CodeLandQuiz\DTO\StudentStatisticsDTO;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Student\Exception\StudentNotFoundException;
use CodeLandQuiz\Student\Http\StudentStatisticsSessionQueryRequest;
use CodeLandQuiz\Student\StudentStatisticsService;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Throwable;

final class StudentStatisticsController
{
    public function __construct(
        private readonly StudentStatisticsService $statisticsService,
        private readonly ResponseFactory $responseFactory,
    ) {
    }

    public function get(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $studentId = $context->getRouteInt('id');
            $statistics = $this->statisticsService->getStatistics(
                $studentId,
            );

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
        } catch (StudentNotFoundException) {
            $this->responseFactory->error(
                $response,
                'Student was not found.',
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

    public function sessions(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $studentId = $context->getRouteInt('id');
            $query = StudentStatisticsSessionQueryRequest::from($request);
            $page = $this->statisticsService->listSessionPerformances(
                $studentId,
                $query,
            );

            $this->responseFactory->json(
                $response,
                $this->sessionPageResponse($page),
            );
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (StudentNotFoundException) {
            $this->responseFactory->error(
                $response,
                'Student was not found.',
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
    private function statisticsResponse(StudentStatisticsDTO $statistics): array
    {
        $summary = $statistics->summary;

        return [
            'student' => $this->studentResponse($statistics->student),
            'summary' => [
                'finishedSessionCount' => $summary->finishedSessionCount,
                'distinctQuizCount' => $summary->distinctQuizCount,
                'totalPossibleAnswerCount' =>
                    $summary->totalPossibleAnswerCount,
                'answerCount' => $summary->answerCount,
                'correctAnswerCount' => $summary->correctAnswerCount,
                'incorrectAnswerCount' => $summary->incorrectAnswerCount,
                'unansweredCount' => $summary->unansweredCount,
                'accuracyPercentage' => $summary->accuracyPercentage,
                'answerRatePercentage' => $summary->answerRatePercentage,
                'totalScore' => $summary->totalScore,
                'averageScore' => $summary->averageScore,
                'averageScorePercentage' =>
                    $summary->averageScorePercentage,
                'highestScore' => $summary->highestScore,
                'highestScorePercentage' =>
                    $summary->highestScorePercentage,
                'averageResponseTimeMs' =>
                    $summary->averageResponseTimeMs,
                'topThreeCount' => $summary->topThreeCount,
                'firstPlaceCount' => $summary->firstPlaceCount,
            ],
            'quizzes' => array_map(
                $this->quizStatisticsResponse(...),
                $statistics->quizzes,
            ),
        ];
    }

    /**
     * @return array<string, int|string|float|null>
     */
    private function quizStatisticsResponse(
        StudentQuizStatisticsDTO $quiz,
    ): array {
        return [
            'quizId' => $quiz->quizId,
            'quizTitle' => $quiz->quizTitle,
            'quizVersion' => $quiz->quizVersion,
            'finishedSessionCount' => $quiz->finishedSessionCount,
            'totalPossibleAnswerCount' =>
                $quiz->totalPossibleAnswerCount,
            'answerCount' => $quiz->answerCount,
            'correctAnswerCount' => $quiz->correctAnswerCount,
            'incorrectAnswerCount' => $quiz->incorrectAnswerCount,
            'unansweredCount' => $quiz->unansweredCount,
            'accuracyPercentage' => $quiz->accuracyPercentage,
            'answerRatePercentage' => $quiz->answerRatePercentage,
            'totalScore' => $quiz->totalScore,
            'averageScore' => $quiz->averageScore,
            'averageScorePercentage' => $quiz->averageScorePercentage,
            'highestScore' => $quiz->highestScore,
            'highestScorePercentage' => $quiz->highestScorePercentage,
            'averageResponseTimeMs' => $quiz->averageResponseTimeMs,
            'topThreeCount' => $quiz->topThreeCount,
            'firstPlaceCount' => $quiz->firstPlaceCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPageResponse(
        StudentSessionPerformancePageDTO $page,
    ): array {
        return [
            'sessions' => array_map(
                $this->sessionPerformanceResponse(...),
                $page->items,
            ),
            'pagination' => [
                'pageIndex' => $page->pageIndex,
                'pageSize' => $page->pageSize,
                'totalItems' => $page->totalItems,
                'totalPages' => $page->totalPages,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPerformanceResponse(
        StudentSessionPerformanceDTO $performance,
    ): array {
        return [
            'sessionId' => $performance->sessionId,
            'quiz' => [
                'id' => $performance->quizId,
                'title' => $performance->quizTitle,
                'version' => $performance->quizVersion,
            ],
            'startedAt' => $performance->startedAt->format(DATE_ATOM),
            'endedAt' => $performance->endedAt->format(DATE_ATOM),
            'questionCount' => $performance->questionCount,
            'maxPossibleScore' => $performance->maxPossibleScore,
            'totalScore' => $performance->totalScore,
            'scorePercentage' => $performance->scorePercentage,
            'answerCount' => $performance->answerCount,
            'correctAnswerCount' => $performance->correctAnswerCount,
            'incorrectAnswerCount' => $performance->incorrectAnswerCount,
            'unansweredCount' => $performance->unansweredCount,
            'accuracyPercentage' => $performance->accuracyPercentage,
            'answerRatePercentage' => $performance->answerRatePercentage,
            'averageResponseTimeMs' =>
                $performance->averageResponseTimeMs,
            'participantCount' => $performance->participantCount,
            'finalRank' => $performance->finalRank,
        ];
    }

    /**
     * @return array{id: int, firstName: string, lastName: string,
     *     username: string, isActive: bool}
     */
    private function studentResponse(StudentItemDTO $student): array
    {
        return [
            'id' => $student->id,
            'firstName' => $student->firstName,
            'lastName' => $student->lastName,
            'username' => $student->username,
            'isActive' => $student->isActive,
        ];
    }
}
