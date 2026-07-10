<?php

declare(strict_types=1);

namespace CodeLandQuiz\Middleware;

use CodeLandQuiz\Auth\AuthorizationService;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Model\UserRole;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use RuntimeException;
use Throwable;

final readonly class RoleMiddleware
{
    /**
     * @param UserRole[] $allowedRoles
     */
    public function __construct(
        private AuthorizationService $authorizationService,
        private ResponseFactory $responseFactory,
        private array $allowedRoles,
    ) {
    }

    /**
     * @param callable(Request, Response, RequestContext): void $next
     */
    public function handle(
        Request $request,
        Response $response,
        RequestContext $context,
        callable $next,
    ): void {
        try {
            $user = $context->getAuthenticatedUser();

            $this->authorizationService->ensureGranted(
                $user,
                ...$this->allowedRoles,
            );

            $next($request, $response, $context);
        } catch (RuntimeException) {
            $this->responseFactory->error(
                $response,
                'Access denied.',
                403,
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