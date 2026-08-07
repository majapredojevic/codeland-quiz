<?php

declare(strict_types=1);

namespace CodeLandQuiz;

use CodeLandQuiz\Bootstrap\ApplicationFactory;
use CodeLandQuiz\Controller\HealthController;
use CodeLandQuiz\Model\UserRole;
use CodeLandQuiz\Support\Router;
use CodeLandQuiz\WebSocket\WebSocketGatewayRouter;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;

final class Application
{
    private Server $server;

    private Router $router;

    private WebSocketGatewayRouter $webSocketGateway;

    private ApplicationFactory $applicationFactory;

    public function __construct(
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 9501,
    ) {
        $this->server = new Server($this->host, $this->port);
        $this->router = new Router();
        $this->applicationFactory = new ApplicationFactory(
            projectRootPath: dirname(__DIR__),
            server: $this->server,
        );
        $this->webSocketGateway =
            $this->applicationFactory->createWebSocketGatewayRouter();
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

        $gameController =
            $this->applicationFactory->createGameController();

        $this->router->get(
            '/api/game/session/{gamePin}',
            $gameController->preview(...),
        );

        $this->router->post(
            '/api/game/join',
            $gameController->join(...),
        );

        $csrfMiddleware =
            $this->applicationFactory->createCsrfMiddleware();

        $authenticationMiddleware =
            $this->applicationFactory->createAuthenticationMiddleware();

        $passwordChangeRequiredMiddleware =
            $this->applicationFactory
                ->createPasswordChangeRequiredMiddleware();

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

        $topicController =
            $this->applicationFactory->createTopicController();

        $quizController =
            $this->applicationFactory->createQuizController();

        $questionController =
            $this->applicationFactory->createQuestionController();

        $quizSessionController =
            $this->applicationFactory->createQuizSessionController();

        $studentController =
            $this->applicationFactory->createStudentController();

        $this->router->get(
            '/api/auth/me',
            $this->applicationFactory->createMeController(),
            [
                $authenticationMiddleware->handle(...),
            ],
        );

        $this->router->post(
            '/api/auth/change-password',
            $this->applicationFactory->createChangePasswordController(),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->post(
            '/api/admin/users',
            $adminUserController,
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $adminOnlyMiddleware->handle(...),
            ],
        );

        $this->router->get(
            '/api/admin/users',
            $adminUserController->list(...),
            [
                $authenticationMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $adminOnlyMiddleware->handle(...),
            ],
        );

        $this->router->get(
            '/api/students',
            $studentController->list(...),
            [
                $authenticationMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->get(
            '/api/students/{id}',
            $studentController->get(...),
            [
                $authenticationMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->post(
            '/api/students',
            $studentController->create(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->patch(
            '/api/students/{id}',
            $studentController->update(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->patch(
            '/api/students/{id}/activate',
            $studentController->activate(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->patch(
            '/api/students/{id}/deactivate',
            $studentController->deactivate(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->get(
            '/api/topics',
            $topicController->list(...),
            [
                $authenticationMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->get(
            '/api/topics/{id}',
            $topicController->get(...),
            [
                $authenticationMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->get(
            '/api/quizzes',
            $quizController->list(...),
            [
                $authenticationMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->get(
            '/api/quizzes/{id}',
            $quizController->get(...),
            [
                $authenticationMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->get(
            '/api/quizzes/{quizId}/questions',
            $questionController->list(...),
            [
                $authenticationMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->get(
            '/api/quizzes/{quizId}/questions/{questionId}',
            $questionController->get(...),
            [
                $authenticationMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->put(
            '/api/quizzes/{quizId}/questions/order',
            $questionController->reorder(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->post(
            '/api/quizzes/{quizId}/questions',
            $questionController->create(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->patch(
            '/api/quizzes/{quizId}/questions/{questionId}',
            $questionController->update(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->delete(
            '/api/quizzes/{quizId}/questions/{questionId}',
            $questionController->delete(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->post(
            '/api/quizzes',
            $quizController->create(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->patch(
            '/api/quizzes/{id}',
            $quizController->update(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->patch(
            '/api/quizzes/{id}/activate',
            $quizController->activate(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->patch(
            '/api/quizzes/{id}/deactivate',
            $quizController->deactivate(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->delete(
            '/api/quizzes/{id}',
            $quizController->delete(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->post(
            '/api/quizzes/{quizId}/sessions',
            $quizSessionController->create(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->get(
            '/api/sessions/{id}',
            $quizSessionController->get(...),
            [
                $authenticationMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->get(
            '/api/sessions/{id}/participants',
            $quizSessionController->listParticipants(...),
            [
                $authenticationMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->delete(
            '/api/sessions/{id}/participants/{participantId}',
            $quizSessionController->removeParticipant(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->post(
            '/api/sessions/{id}/start',
            $quizSessionController->start(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->post(
            '/api/sessions/{id}/questions/current/close',
            $quizSessionController->closeCurrentQuestion(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->post(
            '/api/sessions/{id}/questions/next',
            $quizSessionController->startNextQuestion(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->post(
            '/api/sessions/{id}/finish',
            $quizSessionController->finish(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->post(
            '/api/topics',
            $topicController->create(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->patch(
            '/api/topics/{id}',
            $topicController->update(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->delete(
            '/api/topics/{id}',
            $topicController->delete(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
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

        $this->router->get(
            '/api/admin/users/{id}',
            $adminUserController->get(...),
            [
                $authenticationMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $adminOnlyMiddleware->handle(...),
            ],
        );

        $this->router->patch(
            '/api/admin/users/{id}',
            $adminUserController->update(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $adminOnlyMiddleware->handle(...),
            ],
        );

        $this->router->patch(
            '/api/admin/users/{id}/activate',
            $adminUserController->activate(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $adminOnlyMiddleware->handle(...),
            ],
        );

        $this->router->patch(
            '/api/admin/users/{id}/deactivate',
            $adminUserController->deactivate(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $adminOnlyMiddleware->handle(...),
            ],
        );

        $this->router->post(
            '/api/admin/users/{id}/reset-password',
            $adminUserController->resetPassword(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
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
            $this->webSocketGateway->open($server, $request);
        });

        $this->server->on('message', function (Server $server, Frame $frame): void {
            $this->webSocketGateway->message($server, $frame);
        });

        $this->server->on('close', function (Server $server, int $fd): void {
            $this->webSocketGateway->close($server, $fd);
        });
    }
}
