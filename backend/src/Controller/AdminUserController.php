<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\Admin\Http\CreateTeacherRequest;
use CodeLandQuiz\Admin\Http\ListTeachersRequest;
use CodeLandQuiz\Admin\UserManagementService;
use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\DTO\CreateTeacherResult;
use CodeLandQuiz\DTO\UserListItemDTO;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Http\RequestContext;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use CodeLandQuiz\Admin\Exception\TeacherNotFoundException;
use RuntimeException;
use Throwable;

final class AdminUserController
{
    public function __construct(
        private readonly UserManagementService $userManagementService,
        private readonly ResponseFactory $responseFactory,
        private readonly AppConfig $config,
    ) {}

    public function __invoke(Request $request, Response $response): void
    {
        try {
            $dto = CreateTeacherRequest::from($request);
            $result = $this->userManagementService->createTeacher($dto);

            $this->responseFactory->json(
                $response,
                $this->createTeacherResponse($result),
                201,
            );
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error($response, $exception->getMessage(), 400);
        } catch (RuntimeException $exception) {
            $this->responseFactory->error($response, $exception->getMessage(), 409);
        } catch (Throwable) {
            $this->responseFactory->error($response, 'Internal server error.', 500);
        }
    }

    public function get(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $teacherId = $context->getRouteInt('id');
            $teacher = $this->userManagementService->getTeacher($teacherId);

            $this->responseFactory->json($response, [
                'user' => $this->userResponse($teacher),
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (TeacherNotFoundException $exception) {
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

    public function list(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $dto = ListTeachersRequest::from(
                $request,
                $this->config,
            );
            $result = $this->userManagementService->listTeachers($dto);

            $this->responseFactory->json($response, [
                'users' => array_map(
                    fn(UserListItemDTO $teacher): array => $this->userResponse($teacher),
                    $result->teachers,
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

    /**
     * @return array<string, mixed>
     */
    private function createTeacherResponse(CreateTeacherResult $result): array
    {
        return [
            'user' => [
                'id' => $result->userId,
                'name' => $result->name,
                'email' => $result->email,
                'role' => $result->role->value,
            ],
            'temporaryPassword' => $result->temporaryPassword,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userResponse(UserListItemDTO $teacher): array
    {
        return [
            'id' => $teacher->id,
            'name' => $teacher->name,
            'email' => $teacher->email,
            'role' => $teacher->role->value,
            'isActive' => $teacher->isActive,
            'mustChangePassword' => $teacher->mustChangePassword,
        ];
    }
}
