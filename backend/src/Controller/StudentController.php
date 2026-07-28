<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\DTO\StudentItemDTO;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Student\Exception\StudentNotFoundException;
use CodeLandQuiz\Student\Exception\StudentUsernameAlreadyExistsException;
use CodeLandQuiz\Student\Http\CreateStudentRequest;
use CodeLandQuiz\Student\Http\StudentListQueryRequest;
use CodeLandQuiz\Student\Http\UpdateStudentRequest;
use CodeLandQuiz\Student\StudentService;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Throwable;

final class StudentController
{
    public function __construct(
        private readonly StudentService $studentService,
        private readonly ResponseFactory $responseFactory,
    ) {
    }

    public function list(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $query = StudentListQueryRequest::from($request);
            $result = $this->studentService->listStudents($query);

            $this->responseFactory->json($response, [
                'students' => array_map(
                    fn (StudentItemDTO $student): array =>
                        $this->studentResponse($student),
                    $result->items,
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
            $studentId = $context->getRouteInt('id');
            $student = $this->studentService->getStudent($studentId);

            $this->responseFactory->json($response, [
                'student' => $this->studentResponse($student),
            ]);
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

    public function update(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $studentId = $context->getRouteInt('id');
            $dto = UpdateStudentRequest::from($request);
            $actorUserId = $context->getCurrentUser()->id;
            $student = $this->studentService->updateStudent(
                $actorUserId,
                $studentId,
                $dto,
            );

            $this->responseFactory->json($response, [
                'student' => $this->studentResponse($student),
            ]);
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
        } catch (StudentUsernameAlreadyExistsException) {
            $this->responseFactory->error(
                $response,
                'Student username is already in use.',
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

    public function activate(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        $this->changeActiveStatus(
            $response,
            $context,
            true,
        );
    }

    public function deactivate(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        $this->changeActiveStatus(
            $response,
            $context,
            false,
        );
    }

    public function create(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $dto = CreateStudentRequest::from($request);
            $actorUserId = $context->getCurrentUser()->id;
            $student = $this->studentService->createStudent(
                $actorUserId,
                $dto,
            );

            $this->responseFactory->json($response, [
                'student' => $this->studentResponse($student),
            ], 201);
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (StudentUsernameAlreadyExistsException) {
            $this->responseFactory->error(
                $response,
                'Student username is already in use.',
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

    private function changeActiveStatus(
        Response $response,
        RequestContext $context,
        bool $shouldBeActive,
    ): void {
        try {
            $studentId = $context->getRouteInt('id');
            $actorUserId = $context->getCurrentUser()->id;
            $student = $shouldBeActive
                ? $this->studentService->activateStudent(
                    $actorUserId,
                    $studentId,
                )
                : $this->studentService->deactivateStudent(
                    $actorUserId,
                    $studentId,
                );

            $this->responseFactory->json($response, [
                'student' => $this->studentResponse($student),
            ]);
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
    private function studentResponse(StudentItemDTO $student): array
    {
        return [
            'id' => $student->id,
            'firstName' => $student->firstName,
            'lastName' => $student->lastName,
            'username' => $student->username,
            'isActive' => $student->isActive,
            'createdAt' => $student->createdAt->format(DATE_ATOM),
            'updatedAt' => $student->updatedAt->format(DATE_ATOM),
        ];
    }
}
