<?php

declare(strict_types=1);

namespace CodeLandQuiz;

use CodeLandQuiz\Bootstrap\ApplicationFactory;
use CodeLandQuiz\Controller\HealthController;
use CodeLandQuiz\Support\Router;
use CodeLandQuiz\WebSocket\EchoGateway;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;

final class Application
{
    private Server $server;

    private Router $router;

    private EchoGateway $echoGateway;

    private ApplicationFactory $applicationFactory;

    public function __construct(
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 9501,
    ) {
        $this->server = new Server($this->host, $this->port);
        $this->router = new Router();
        $this->echoGateway = new EchoGateway();
        $this->applicationFactory = new ApplicationFactory(dirname(__DIR__));
    }

    public function run(): void
    {
        $this->configureServer();
        $this->registerRoutes();
        $this->registerEvents();

        $this->server->start();
    }

    private function configureServer(): void
    {
        $this->server->set([
            'worker_num' => 1,
            'enable_coroutine' => true,
        ]);
    }

    private function registerRoutes(): void
    {
        $this->router->get('/health', new HealthController());

        $this->router->post(
            '/api/auth/login',
            $this->applicationFactory->createAuthController(),
        );

        $this->router->post(
            '/api/auth/refresh',
            $this->applicationFactory->createRefreshController(),
        );
    }

    private function registerEvents(): void
    {
        $this->server->on('start', function (): void {
            echo sprintf(
                "CodeLand Quiz OpenSwoole server started on http://localhost:%d\n",
                $this->port,
            );
        });

        $this->server->on('request', function (Request $request, Response $response): void {
            $this->router->dispatch($request, $response);
        });

        $this->server->on('open', function (Server $server, Request $request): void {
            $this->echoGateway->open($request);
        });

        $this->server->on('message', function (Server $server, Frame $frame): void {
            $this->echoGateway->message($server, $frame);
        });

        $this->server->on('close', function (Server $server, int $fd): void {
            $this->echoGateway->close($fd);
        });
    }
}
