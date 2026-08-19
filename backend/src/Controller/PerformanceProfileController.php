<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Observability\PerformanceProfiler;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

final readonly class PerformanceProfileController
{
    public function __construct(
        private PerformanceProfiler $profiler,
        private ResponseFactory $responseFactory,
    ) {
    }

    public function snapshot(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        $response->header('Cache-Control', 'no-store');
        $this->responseFactory->json($response, $this->profiler->snapshot());
    }

    public function reset(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        $this->profiler->reset();
        $response->header('Cache-Control', 'no-store');
        $this->responseFactory->json($response, [
            'enabled' => $this->profiler->isEnabled(),
            'reset' => true,
        ]);
    }
}
