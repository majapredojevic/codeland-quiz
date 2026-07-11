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
use CodeLandQuiz\Model\UserRole;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Support\JsonResponse;

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

        $csrfMiddleware =
            $this->applicationFactory->createCsrfMiddleware();

        $authenticationMiddleware =
            $this->applicationFactory->createAuthenticationMiddleware();

        $adminOnlyMiddleware =
            $this->applicationFactory->createRoleMiddleware(
                UserRole::ADMIN,
            );

        $teacherAccessMiddleware =
            $this->applicationFactory->createRoleMiddleware(
                UserRole::ADMIN,
                UserRole::TEACHER,
            );

        $adminUserController =
            $this->applicationFactory->createAdminUserController();

        $this->router->get(
            '/api/auth/me',
            $this->applicationFactory->createMeController(),
            [
                $authenticationMiddleware->handle(...),
            ],
        );

        $this->router->get(
            '/api/auth/admin-test',
            $this->applicationFactory->createMeController(),
            [
                $authenticationMiddleware->handle(...),
                $adminOnlyMiddleware->handle(...),
            ],
        );

        $this->router->post(
            '/api/admin/users',
            $adminUserController,
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $adminOnlyMiddleware->handle(...),
            ],
        );

        $this->router->get(
            '/api/admin/users',
            $adminUserController->list(...),
            [
                $authenticationMiddleware->handle(...),
                $adminOnlyMiddleware->handle(...),
            ],
        );
        $this->router->post(
            '/api/auth/logout',
            $this->applicationFactory->createLogoutController(),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
            ],
        );

        $this->router->post(
            '/api/admin/users',
            $this->applicationFactory->createAdminUserController(),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $adminOnlyMiddleware->handle(...),
            ],
        );

        $this->router->get(
            '/api/admin/users/{id}',
            $adminUserController->get(...),
            [
                $authenticationMiddleware->handle(...),
                $adminOnlyMiddleware->handle(...),
            ],
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
