<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\Support\JsonResponse;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use CodeLandQuiz\Http\RequestContext;

final class HealthController
{
    public function __invoke(
        Request $request,
        Response $response,
        RequestContext $context
    ): void {
        JsonResponse::send($response, [
            'status' => 'ok',
            'service' => 'codeland-quiz-backend',
            'server' => 'openswoole',
        ]);
    }
}
