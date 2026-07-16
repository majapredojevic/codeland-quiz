<?php

declare(strict_types=1);

namespace CodeLandQuiz\Middleware;

use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

final readonly class PasswordChangeRequiredMiddleware
{
    public function __construct(
        private ResponseFactory $responseFactory,
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
        $currentUser = $context->getCurrentUser();

        if ($currentUser->mustChangePassword) {
            $this->responseFactory->error(
                $response,
                'Password change is required.',
                403,
            );

            return;
        }

        $next($request, $response, $context);
    }
}
