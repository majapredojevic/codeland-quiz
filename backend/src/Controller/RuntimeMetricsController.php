<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Observability\RuntimeMetrics;
use CodeLandQuiz\WebSocket\ParticipantConnectionRegistry;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\WebSocket\Server;

final readonly class RuntimeMetricsController
{
    public function __construct(
        private Server $server,
        private ParticipantConnectionRegistry $connectionRegistry,
        private RuntimeMetrics $metrics,
        private ResponseFactory $responseFactory,
    ) {
    }

    public function __invoke(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        $response->header('Cache-Control', 'no-store');
        $this->responseFactory->json(
            $response,
            $this->metrics->snapshot(
                $this->server,
                $this->connectionRegistry,
            ),
        );
    }
}
