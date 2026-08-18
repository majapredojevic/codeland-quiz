<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Observability\RuntimeLogger;
use CodeLandQuiz\Observability\RuntimeMetrics;
use CodeLandQuiz\Support\Database;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

final readonly class ReadinessController
{
    public function __construct(
        private Database $database,
        private ResponseFactory $responseFactory,
        private RuntimeLogger $logger,
        private RuntimeMetrics $metrics,
    ) {
    }

    public function __invoke(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        $response->header('Cache-Control', 'no-store');

        if (!$this->metrics->isRuntimeInitialized()) {
            $this->notReady(
                response: $response,
                context: $context,
                reason: 'runtime_initializing',
            );

            return;
        }

        if ($this->database->isReady()) {
            $this->responseFactory->json($response, [
                'status' => 'ready',
                'service' => 'codeland-quiz-backend',
            ]);

            return;
        }

        $this->notReady(
            response: $response,
            context: $context,
            reason: 'database_unavailable',
        );
    }

    private function notReady(
        Response $response,
        RequestContext $context,
        string $reason,
    ): void {
        $this->metrics->recordReadinessFailure();
        $this->logger->warning('health.readiness_failed', [
            'requestId' => $context->getRequestId(),
            'reason' => $reason,
        ]);
        $this->responseFactory->json($response, [
            'status' => 'not_ready',
            'service' => 'codeland-quiz-backend',
        ], 503);
    }
}
