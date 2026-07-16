<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\Auth\AuthCookieService;
use CodeLandQuiz\Auth\Exception\AuthenticatedUserUnavailableException;
use CodeLandQuiz\Auth\Http\ChangePasswordRequest;
use CodeLandQuiz\Auth\UserService;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Throwable;

final class ChangePasswordController
{
    public function __construct(
        private UserService $userService,
        private AuthCookieService $authCookieService,
        private ResponseFactory $responseFactory,
    ) {
    }

    public function __invoke(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $dto = ChangePasswordRequest::from($request);
            $authenticatedUserId = $context
                ->getAuthenticatedUser()
                ->userId;

            $this->userService->changePassword(
                $authenticatedUserId,
                $dto,
            );

            $this->authCookieService->clearAuthenticationCookies($response);

            $response->status(204);
            $response->end();
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (AuthenticatedUserUnavailableException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                401,
            );
        } catch (Throwable) {
            $this->responseFactory->error(
                $response,
                'Internal server error.',
                500,
            );
        }
    }
}
