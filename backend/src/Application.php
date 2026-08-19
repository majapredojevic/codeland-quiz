<?php

declare(strict_types=1);

namespace CodeLandQuiz;

use CodeLandQuiz\Bootstrap\ApplicationFactory;
use CodeLandQuiz\Controller\HealthController;
use CodeLandQuiz\Http\RequestIdGenerator;
use CodeLandQuiz\Model\UserRole;
use CodeLandQuiz\Runtime\OpenSwooleRuntimeSupervisor;
use CodeLandQuiz\Support\Router;
use CodeLandQuiz\WebSocket\WebSocketGatewayRouter;
use CodeLandQuiz\WebSocket\WebSocketHandshakeHandler;
use OpenSwoole\Coroutine;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;
use Throwable;

final class Application
{
    private Server $server;

    private Router $router;

    private WebSocketGatewayRouter $webSocketGateway;

    private WebSocketHandshakeHandler $webSocketHandshakeHandler;

    private ApplicationFactory $applicationFactory;

    private OpenSwooleRuntimeSupervisor $runtimeSupervisor;

    private bool $workerExitCleanupScheduled = false;

    public function __construct(
        private readonly string $host = '0.0.0.0',
        private readonly int $port = 9501,
    ) {
        $this->server = new Server($this->host, $this->port);
        $this->applicationFactory = new ApplicationFactory(
            projectRootPath: dirname(__DIR__),
            server: $this->server,
        );
        $this->router = new Router(
            logger: $this->applicationFactory->getRuntimeLogger(),
            metrics: $this->applicationFactory->getRuntimeMetrics(),
            requestIdGenerator: new RequestIdGenerator(),
            profiler: $this->applicationFactory->getPerformanceProfiler(),
        );
        $this->webSocketGateway =
            $this->applicationFactory->createWebSocketGatewayRouter();
        $this->runtimeSupervisor =
            $this->applicationFactory->createRuntimeSupervisor(
                $this->webSocketGateway,
            );
        $this->webSocketHandshakeHandler = new WebSocketHandshakeHandler(
            gatewayRouter: $this->webSocketGateway,
            profiler: $this->applicationFactory->getPerformanceProfiler(),
        );
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
            'max_request' => 0,
            'max_conn' => $this->applicationFactory
                ->getOpenSwooleMaximumConnections(),
            'max_coroutine' => $this->applicationFactory
                ->getOpenSwooleMaximumCoroutines(),
            'heartbeat_check_interval' => $this->applicationFactory
                ->getOpenSwooleTransportHeartbeatCheckIntervalSeconds(),
            'heartbeat_idle_time' => $this->applicationFactory
                ->getOpenSwooleTransportHeartbeatIdleSeconds(),
            'enable_coroutine' => true,
            'package_max_length' => $this->applicationFactory
                ->getMaximumUploadPackageLengthBytes(),
        ]);
    }

    private function registerRoutes(): void
    {
        $this->router->get('/health', new HealthController());
        $this->router->get(
            '/ready',
            $this->applicationFactory->createReadinessController(),
        );
        $this->router->get(
            '/internal/metrics',
            $this->applicationFactory->createRuntimeMetricsController(),
        );
        $performanceProfileController =
            $this->applicationFactory->createPerformanceProfileController();
        $this->router->get(
            '/internal/profile',
            $performanceProfileController->snapshot(...),
        );
        $this->router->post(
            '/internal/profile/reset',
            $performanceProfileController->reset(...),
        );

        $questionImageController =
            $this->applicationFactory->createQuestionImageController();

        $this->router->get(
            '/media/question-images/{quizId}/{fileName}',
            $questionImageController->media(...),
        );

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

        $quizStatisticsController =
            $this->applicationFactory->createQuizStatisticsController();

        $questionController =
            $this->applicationFactory->createQuestionController();

        $quizSessionController =
            $this->applicationFactory->createQuizSessionController();

        $quizSessionHistoryController =
            $this->applicationFactory->createQuizSessionHistoryController();

        $studentController =
            $this->applicationFactory->createStudentController();

        $studentStatisticsController =
            $this->applicationFactory->createStudentStatisticsController();

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

        $this->router->get(
            '/api/students/{id}/statistics',
            $studentStatisticsController->get(...),
            [
                $authenticationMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->get(
            '/api/students/{id}/statistics/sessions',
            $studentStatisticsController->sessions(...),
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
            '/api/quizzes/{id}/statistics',
            $quizStatisticsController->get(...),
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
            '/api/quizzes/{quizId}/question-images',
            $questionImageController->upload(...),
            [
                $authenticationMiddleware->handle(...),
                $csrfMiddleware->handle(...),
                $passwordChangeRequiredMiddleware->handle(...),
                $teacherAccessMiddleware->handle(...),
            ],
        );

        $this->router->delete(
            '/api/quizzes/{quizId}/question-images/{fileName}',
            $questionImageController->cleanup(...),
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
            '/api/sessions',
            $quizSessionHistoryController->list(...),
            [
                $authenticationMiddleware->handle(...),
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
            '/api/sessions/{id}/results',
            $quizSessionHistoryController->report(...),
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
        $logger = $this->applicationFactory->getRuntimeLogger();

        $this->server->on('start', function (Server $server) use ($logger): void {
            $logger->info('runtime.server_started', [
                'workerPid' => $server->master_pid,
                'port' => $this->port,
            ]);
        });

        $this->server->on(
            'workerStart',
            function (Server $server, int $workerId) use ($logger): void {
                try {
                    $reconciled = $this->applicationFactory
                        ->reconcileParticipantPresence();
                    $logger->info('presence.startup_reconciled', [
                        'workerId' => $workerId,
                        'count' => $reconciled,
                    ]);
                } catch (Throwable $throwable) {
                    $logger->error('presence.startup_reconciliation_failed', [
                        'workerId' => $workerId,
                        'exception' => $throwable::class,
                    ]);
                }

                $this->runtimeSupervisor->start($workerId);
                $this->applicationFactory->getRuntimeMetrics()
                    ->markRuntimeInitialized(true);
                $logger->info('runtime.worker_started', [
                    'workerId' => $workerId,
                    'workerPid' => $server->worker_pid,
                ]);
            },
        );

        $this->server->on(
            'workerExit',
            function (Server $server, int $workerId) use ($logger): void {
                if ($this->workerExitCleanupScheduled) {
                    return;
                }

                $this->workerExitCleanupScheduled = true;
                $this->applicationFactory->getRuntimeMetrics()
                    ->markRuntimeInitialized(false);
                $this->runtimeSupervisor->stop($workerId);

                $cleanupCoroutineId = Coroutine::create(
                    function () use ($logger, $workerId): void {
                        try {
                            $reconciled = $this->applicationFactory
                                ->reconcileParticipantPresence();
                            $logger->info(
                                'presence.worker_exit_reconciled',
                                [
                                    'workerId' => $workerId,
                                    'count' => $reconciled,
                                ],
                            );
                        } catch (Throwable $throwable) {
                            $logger->error(
                                'presence.worker_exit_reconciliation_failed',
                                [
                                    'workerId' => $workerId,
                                    'exception' => $throwable::class,
                                ],
                            );
                        }
                    },
                );

                if (!is_int($cleanupCoroutineId)) {
                    $logger->warning(
                        'presence.worker_exit_reconciliation_not_scheduled',
                        ['workerId' => $workerId],
                    );
                }

                $logger->info('runtime.worker_exiting', [
                    'workerId' => $workerId,
                    'workerPid' => $server->worker_pid,
                ]);
            },
        );

        $this->server->on(
            'workerStop',
            function (Server $server, int $workerId) use ($logger): void {
                $this->applicationFactory->getRuntimeMetrics()
                    ->markRuntimeInitialized(false);
                $this->runtimeSupervisor->stop($workerId);

                $logger->info('runtime.worker_stopped', [
                    'workerId' => $workerId,
                    'workerPid' => $server->worker_pid,
                ]);
            },
        );

        $this->server->on(
            'workerError',
            function (
                Server $server,
                int $workerId,
                int $workerPid,
                int $exitCode,
                int $signal,
            ) use ($logger): void {
                $logger->error('runtime.worker_error', [
                    'workerId' => $workerId,
                    'workerPid' => $workerPid,
                    'exitCode' => $exitCode,
                    'signal' => $signal,
                ]);
            },
        );

        $this->server->on('shutdown', function () use ($logger): void {
            $logger->info('runtime.server_shutdown');
        });

        $this->server->on('request', function (Request $request, Response $response): void {
            $this->router->dispatch($request, $response);
        });

        // OpenSwoole 26.2 emits a PHP dynamic-property deprecation while
        // registering its documented Handshake event. Scope suppression to
        // registration only; runtime warnings remain fully enabled.
        $errorReporting = error_reporting();

        try {
            error_reporting($errorReporting & ~E_DEPRECATED);
            $this->server->on('handshake', function (Request $request, Response $response): bool {
                return $this->webSocketHandshakeHandler->handle(
                    $this->server,
                    $request,
                    $response,
                );
            });
        } finally {
            error_reporting($errorReporting);
        }

        $this->server->on('message', function (Server $server, Frame $frame): void {
            $this->webSocketGateway->message($server, $frame);
        });

        $this->server->on('close', function (Server $server, int $fd): void {
            $this->webSocketGateway->close($server, $fd);
        });
    }
}
