<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Throwable;

final readonly class MeController
{
    public function __construct(
        private ResponseFactory $responseFactory,
    ) {
    }

    public function __invoke(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $user = $context->getAuthenticatedUser();

            $this->responseFactory->json($response, [
                'user' => [
                    'id' => $user->userId,
                    'email' => $user->email,
                    'role' => $user->role->value,
                ],
            ]);
        } catch (Throwable) {
            $this->responseFactory->error(
                $response,
                'Internal server error.',
                500,
            );
        }
    }
}