<?php

declare(strict_types=1);

namespace CodeLandQuiz\Bootstrap;

use CodeLandQuiz\Auth\AuditLogService;
use CodeLandQuiz\Auth\AuthorizationService;
use CodeLandQuiz\Auth\AuthCookieService;
use CodeLandQuiz\Auth\AuthService;
use CodeLandQuiz\Auth\BcryptPasswordHasher;
use CodeLandQuiz\Auth\DatabaseRefreshTokenService;
use CodeLandQuiz\Auth\DefaultCsrfTokenService;
use CodeLandQuiz\Auth\JwtTokenService;
use CodeLandQuiz\Auth\LoginAttemptService;
use CodeLandQuiz\Auth\LoginInputNormalizer;
use CodeLandQuiz\Auth\LoginIpRateLimiter;
use CodeLandQuiz\Auth\SecureTemporaryPasswordGenerator;
use CodeLandQuiz\Auth\UserService;
use CodeLandQuiz\Admin\UserManagementService;
use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\Controller\AdminUserController;
use CodeLandQuiz\Controller\AuthController;
use CodeLandQuiz\Controller\ChangePasswordController;
use CodeLandQuiz\Controller\GameController;
use CodeLandQuiz\Controller\LogoutController;
use CodeLandQuiz\Controller\MeController;
use CodeLandQuiz\Controller\QuestionController;
use CodeLandQuiz\Controller\QuestionImageController;
use CodeLandQuiz\Controller\QuizController;
use CodeLandQuiz\Controller\QuizStatisticsController;
use CodeLandQuiz\Controller\QuizSessionController;
use CodeLandQuiz\Controller\QuizSessionHistoryController;
use CodeLandQuiz\Controller\RefreshController;
use CodeLandQuiz\Controller\StudentController;
use CodeLandQuiz\Controller\StudentStatisticsController;
use CodeLandQuiz\Controller\TopicController;
use CodeLandQuiz\Game\AnswerScoreCalculator;
use CodeLandQuiz\Game\AnswerSubmissionService;
use CodeLandQuiz\Game\AvatarCatalog;
use CodeLandQuiz\Game\GameService;
use CodeLandQuiz\Game\JwtParticipantTokenVerifier;
use CodeLandQuiz\Game\JwtParticipantTokenIssuer;
use CodeLandQuiz\Game\ParticipantConnectionService;
use CodeLandQuiz\Http\CookieReader;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Middleware\AuthenticationMiddleware;
use CodeLandQuiz\Middleware\CsrfMiddleware;
use CodeLandQuiz\Middleware\PasswordChangeRequiredMiddleware;
use CodeLandQuiz\Middleware\RoleMiddleware;
use CodeLandQuiz\Model\UserRole;
use CodeLandQuiz\Repository\MySqlAuditLogRepository;
use CodeLandQuiz\Repository\MySqlLoginAttemptRepository;
use CodeLandQuiz\Repository\MySqlParticipantAnswerRepository;
use CodeLandQuiz\Repository\MySqlQuestionRepository;
use CodeLandQuiz\Repository\MySqlQuestionImageReferenceRepository;
use CodeLandQuiz\Repository\MySqlQuizRepository;
use CodeLandQuiz\Repository\MySqlQuizStatisticsRepository;
use CodeLandQuiz\Repository\MySqlQuizSessionHistoryRepository;
use CodeLandQuiz\Repository\MySqlQuizSessionReportRepository;
use CodeLandQuiz\Repository\MySqlQuizSessionRepository;
use CodeLandQuiz\Repository\MySqlQuizSessionResultRepository;
use CodeLandQuiz\Repository\MySqlRefreshTokenRepository;
use CodeLandQuiz\Repository\MySqlSessionQuestionRepository;
use CodeLandQuiz\Repository\MySqlSessionParticipantRepository;
use CodeLandQuiz\Repository\MySqlStudentRepository;
use CodeLandQuiz\Repository\MySqlStudentStatisticsRepository;
use CodeLandQuiz\Repository\MySqlTopicRepository;
use CodeLandQuiz\Repository\MySqlUserRepository;
use CodeLandQuiz\Student\StudentService;
use CodeLandQuiz\Student\StudentStatisticsAssembler;
use CodeLandQuiz\Student\StudentStatisticsService;
use CodeLandQuiz\Support\Database;
use CodeLandQuiz\Support\ClientAddress;
use CodeLandQuiz\Support\Environment;
use CodeLandQuiz\Support\PdoTransactionManager;
use CodeLandQuiz\Question\QuestionContentValidator;
use CodeLandQuiz\Question\QuestionService;
use CodeLandQuiz\QuestionImage\QuestionImageService;
use CodeLandQuiz\QuestionImage\QuestionImageStorage;
use CodeLandQuiz\Quiz\QuizService;
use CodeLandQuiz\Quiz\QuizStatisticsAssembler;
use CodeLandQuiz\Quiz\QuizStatisticsService;
use CodeLandQuiz\QuizSession\ClosedQuestionResultAssembler;
use CodeLandQuiz\QuizSession\FinalQuizSessionResultAssembler;
use CodeLandQuiz\QuizSession\PublicSessionQuestionMapper;
use CodeLandQuiz\QuizSession\QuizSessionHistoryService;
use CodeLandQuiz\QuizSession\QuizSessionReportAssembler;
use CodeLandQuiz\QuizSession\QuizSessionService;
use CodeLandQuiz\QuizSession\SecureGamePinGenerator;
use CodeLandQuiz\Topic\TopicService;
use CodeLandQuiz\WebSocket\ClosedQuestionWebSocketNotifier;
use CodeLandQuiz\WebSocket\EchoGateway;
use CodeLandQuiz\WebSocket\FinishedSessionWebSocketNotifier;
use CodeLandQuiz\WebSocket\ParticipantConnectionRegistry;
use CodeLandQuiz\WebSocket\ParticipantRemovalWebSocketNotifier;
use CodeLandQuiz\WebSocket\ParticipantWebSocketGateway;
use CodeLandQuiz\WebSocket\ParticipantWebSocketSender;
use CodeLandQuiz\WebSocket\SessionWebSocketBroadcaster;
use CodeLandQuiz\WebSocket\SessionWebSocketPayloadMapper;
use CodeLandQuiz\WebSocket\WebSocketGatewayRouter;
use CodeLandQuiz\WebSocket\WebSocketAbuseLimiter;
use CodeLandQuiz\WebSocket\WebSocketConnectionLimiter;
use CodeLandQuiz\WebSocket\WebSocketFramePolicy;
use CodeLandQuiz\WebSocket\WebSocketOriginPolicy;
use CodeLandQuiz\WebSocket\WebSocketRoutePolicy;
use CodeLandQuiz\WebSocket\WebSocketMessageEncoder;
use OpenSwoole\WebSocket\Server;

final class ApplicationFactory
{
    private Environment $environment;

    private AppConfig $config;

    private Database $database;

    private QuestionImageStorage $questionImageStorage;

    private Server $server;

    private ParticipantConnectionRegistry $participantConnectionRegistry;

    private WebSocketMessageEncoder $webSocketMessageEncoder;

    private SessionWebSocketBroadcaster $sessionWebSocketBroadcaster;

    private SessionWebSocketPayloadMapper $sessionWebSocketPayloadMapper;

    private MySqlQuizSessionResultRepository $quizSessionResultRepository;

    private ClosedQuestionResultAssembler $closedQuestionResultAssembler;

    private FinalQuizSessionResultAssembler $finalQuizSessionResultAssembler;

    private ParticipantWebSocketSender $participantWebSocketSender;

    private ParticipantRemovalWebSocketNotifier $participantRemovalWebSocketNotifier;

    private ClosedQuestionWebSocketNotifier $closedQuestionWebSocketNotifier;

    private FinishedSessionWebSocketNotifier $finishedSessionWebSocketNotifier;

    public function __construct(string $projectRootPath, Server $server)
    {
        $this->server = $server;
        $this->environment = new Environment($projectRootPath);
        $this->config = new AppConfig($this->environment);
        $this->database = new Database($this->environment);
        $this->questionImageStorage = new QuestionImageStorage($this->config);
        $this->participantConnectionRegistry =
            new ParticipantConnectionRegistry();
        $this->webSocketMessageEncoder = new WebSocketMessageEncoder();
        $this->sessionWebSocketBroadcaster =
            new SessionWebSocketBroadcaster(
                server: $this->server,
                connectionRegistry: $this->participantConnectionRegistry,
                messageEncoder: $this->webSocketMessageEncoder,
            );
        $this->sessionWebSocketPayloadMapper =
            new SessionWebSocketPayloadMapper();
        $this->quizSessionResultRepository =
            new MySqlQuizSessionResultRepository($this->database);
        $this->closedQuestionResultAssembler =
            new ClosedQuestionResultAssembler(
                results: $this->quizSessionResultRepository,
            );
        $this->finalQuizSessionResultAssembler =
            new FinalQuizSessionResultAssembler(
                results: $this->quizSessionResultRepository,
            );
        $this->participantWebSocketSender = new ParticipantWebSocketSender(
            server: $this->server,
            connectionRegistry: $this->participantConnectionRegistry,
            messageEncoder: $this->webSocketMessageEncoder,
        );
        $this->participantRemovalWebSocketNotifier =
            new ParticipantRemovalWebSocketNotifier(
                server: $this->server,
                connectionRegistry: $this->participantConnectionRegistry,
                participantSender: $this->participantWebSocketSender,
            );
        $this->closedQuestionWebSocketNotifier =
            new ClosedQuestionWebSocketNotifier(
                sessionBroadcaster: $this->sessionWebSocketBroadcaster,
                participantSender: $this->participantWebSocketSender,
                payloadMapper: $this->sessionWebSocketPayloadMapper,
            );
        $this->finishedSessionWebSocketNotifier =
            new FinishedSessionWebSocketNotifier(
                sessionBroadcaster: $this->sessionWebSocketBroadcaster,
                participantSender: $this->participantWebSocketSender,
                payloadMapper: $this->sessionWebSocketPayloadMapper,
            );
    }

    public function createCsrfMiddleware(): CsrfMiddleware
    {
        return new CsrfMiddleware(
            csrfTokenService: new DefaultCsrfTokenService(),
            cookieReader: new CookieReader(),
            config: $this->config,
            responseFactory: new ResponseFactory(),
        );
    }

    public function getMaximumUploadPackageLengthBytes(): int
    {
        return $this->config->getMaximumUploadPackageLengthBytes();
    }

    public function createAuthController(): AuthController
    {
        return new AuthController(
            authService: $this->createAuthService(),
            authCookieService: $this->createAuthCookieService(),
            responseFactory: new ResponseFactory(),
            inputNormalizer: new LoginInputNormalizer(),
            clientAddress: new ClientAddress(),
        );
    }

    public function createRefreshController(): RefreshController
    {
        return new RefreshController(
            authService: $this->createAuthService(),
            authCookieService: $this->createAuthCookieService(),
            responseFactory: new ResponseFactory(),
            cookieReader: new CookieReader(),
            config: $this->config,
        );
    }

    public function createLogoutController(): LogoutController
    {
        $refreshTokenRepository = new MySqlRefreshTokenRepository($this->database);

        return new LogoutController(
            refreshTokenService: new DatabaseRefreshTokenService(
                refreshTokens: $refreshTokenRepository,
                config: $this->config,
                transactionManager: new PdoTransactionManager($this->database),
            ),
            authCookieService: $this->createAuthCookieService(),
            cookieReader: new CookieReader(),
            config: $this->config,
            responseFactory: new ResponseFactory(),
        );
    }

    public function createMeController(): MeController
    {
        return new MeController(
            responseFactory: new ResponseFactory(),
        );
    }

    public function createAuthenticationMiddleware(): AuthenticationMiddleware
    {
        return new AuthenticationMiddleware(
            jwtService: new JwtTokenService(
                config: $this->config,
                environment: $this->environment,
            ),
            cookieReader: new CookieReader(),
            config: $this->config,
            responseFactory: new ResponseFactory(),
            users: new MySqlUserRepository($this->database),
        );
    }

    public function createPasswordChangeRequiredMiddleware():
        PasswordChangeRequiredMiddleware
    {
        return new PasswordChangeRequiredMiddleware(
            responseFactory: new ResponseFactory(),
        );
    }

    public function createRoleMiddleware(
        UserRole ...$allowedRoles,
    ): RoleMiddleware {
        return new RoleMiddleware(
            authorizationService: new AuthorizationService(),
            responseFactory: new ResponseFactory(),
            allowedRoles: $allowedRoles,
        );
    }

    public function createAdminUserController(): AdminUserController
    {
        $userRepository = new MySqlUserRepository($this->database);
        $refreshTokenRepository = new MySqlRefreshTokenRepository($this->database);
        $auditLogRepository = new MySqlAuditLogRepository($this->database);

        return new AdminUserController(
            userManagementService: new UserManagementService(
                users: $userRepository,
                refreshTokens: $refreshTokenRepository,
                temporaryPasswordGenerator: new SecureTemporaryPasswordGenerator(),
                passwordHasher: new BcryptPasswordHasher(),
                auditLogService: new AuditLogService($auditLogRepository),
                transactionManager: new PdoTransactionManager($this->database),
            ),
            responseFactory: new ResponseFactory(),
            config: $this->config,
        );
    }

    public function createTopicController(): TopicController
    {
        $topicRepository = new MySqlTopicRepository($this->database);
        $auditLogRepository = new MySqlAuditLogRepository($this->database);

        return new TopicController(
            topicService: new TopicService(
                topics: $topicRepository,
                auditLogService: new AuditLogService($auditLogRepository),
                transactionManager: new PdoTransactionManager($this->database),
            ),
            responseFactory: new ResponseFactory(),
            config: $this->config,
        );
    }

    public function createQuizController(): QuizController
    {
        $quizRepository = new MySqlQuizRepository($this->database);
        $topicRepository = new MySqlTopicRepository($this->database);
        $questionRepository = new MySqlQuestionRepository($this->database);
        $auditLogRepository = new MySqlAuditLogRepository($this->database);

        return new QuizController(
            quizService: new QuizService(
                quizzes: $quizRepository,
                topics: $topicRepository,
                questions: $questionRepository,
                questionContentValidator: new QuestionContentValidator(),
                auditLogService: new AuditLogService($auditLogRepository),
                transactionManager: new PdoTransactionManager($this->database),
            ),
            responseFactory: new ResponseFactory(),
            config: $this->config,
        );
    }

    public function createQuizStatisticsController(): QuizStatisticsController
    {
        $quizRepository = new MySqlQuizRepository($this->database);
        $statisticsRepository = new MySqlQuizStatisticsRepository(
            $this->database,
        );

        return new QuizStatisticsController(
            statisticsService: new QuizStatisticsService(
                quizzes: $quizRepository,
                statisticsAssembler: new QuizStatisticsAssembler(
                    statistics: $statisticsRepository,
                ),
            ),
            responseFactory: new ResponseFactory(),
        );
    }

    public function createQuestionController(): QuestionController
    {
        $questionRepository = new MySqlQuestionRepository($this->database);
        $quizRepository = new MySqlQuizRepository($this->database);
        $auditLogRepository = new MySqlAuditLogRepository($this->database);

        return new QuestionController(
            questionService: new QuestionService(
                questions: $questionRepository,
                quizzes: $quizRepository,
                questionImages: $this->questionImageStorage,
                questionContentValidator: new QuestionContentValidator(),
                auditLogService: new AuditLogService($auditLogRepository),
                transactionManager: new PdoTransactionManager($this->database),
            ),
            responseFactory: new ResponseFactory(),
        );
    }

    public function createQuestionImageController(): QuestionImageController
    {
        return new QuestionImageController(
            questionImageService: new QuestionImageService(
                quizzes: new MySqlQuizRepository($this->database),
                references: new MySqlQuestionImageReferenceRepository(
                    $this->database,
                ),
                storage: $this->questionImageStorage,
                transactionManager: new PdoTransactionManager($this->database),
            ),
            questionImageStorage: $this->questionImageStorage,
            responseFactory: new ResponseFactory(),
        );
    }

    public function createQuizSessionController(): QuizSessionController
    {
        $quizRepository = new MySqlQuizRepository($this->database);
        $questionRepository = new MySqlQuestionRepository($this->database);
        $sessionRepository = new MySqlQuizSessionRepository($this->database);
        $sessionQuestionRepository = new MySqlSessionQuestionRepository(
            $this->database,
        );
        $participantRepository = new MySqlSessionParticipantRepository(
            $this->database,
        );
        $auditLogRepository = new MySqlAuditLogRepository($this->database);

        return new QuizSessionController(
            quizSessionService: new QuizSessionService(
                quizzes: $quizRepository,
                questions: $questionRepository,
                sessions: $sessionRepository,
                sessionQuestions: $sessionQuestionRepository,
                publicQuestionMapper: new PublicSessionQuestionMapper(),
                questionContentValidator: new QuestionContentValidator(),
                gamePinGenerator: new SecureGamePinGenerator(),
                auditLogService: new AuditLogService($auditLogRepository),
                transactionManager: new PdoTransactionManager($this->database),
                sessionResults: $this->quizSessionResultRepository,
                closedQuestionResultAssembler:
                    $this->closedQuestionResultAssembler,
                finalResultAssembler:
                    $this->finalQuizSessionResultAssembler,
                participants: $participantRepository,
            ),
            responseFactory: new ResponseFactory(),
            sessionWebSocketBroadcaster: $this->sessionWebSocketBroadcaster,
            webSocketPayloadMapper: $this->sessionWebSocketPayloadMapper,
            closedQuestionNotifier: $this->closedQuestionWebSocketNotifier,
            finishedSessionNotifier:
                $this->finishedSessionWebSocketNotifier,
            participantRemovalNotifier:
                $this->participantRemovalWebSocketNotifier,
        );
    }

    public function createQuizSessionHistoryController(): QuizSessionHistoryController
    {
        $sessionRepository = new MySqlQuizSessionRepository($this->database);
        $historyRepository = new MySqlQuizSessionHistoryRepository(
            $this->database,
        );
        $reportRepository = new MySqlQuizSessionReportRepository(
            $this->database,
        );

        return new QuizSessionHistoryController(
            historyService: new QuizSessionHistoryService(
                history: $historyRepository,
                sessions: $sessionRepository,
                reportAssembler: new QuizSessionReportAssembler(
                    reports: $reportRepository,
                    finalResultAssembler:
                        $this->finalQuizSessionResultAssembler,
                ),
            ),
            responseFactory: new ResponseFactory(),
        );
    }

    public function createGameController(): GameController
    {
        $sessionRepository = new MySqlQuizSessionRepository($this->database);
        $studentRepository = new MySqlStudentRepository($this->database);
        $participantRepository = new MySqlSessionParticipantRepository(
            $this->database,
        );
        $avatarCatalog = new AvatarCatalog();

        return new GameController(
            gameService: new GameService(
                sessions: $sessionRepository,
                students: $studentRepository,
                participants: $participantRepository,
                avatarCatalog: $avatarCatalog,
                participantTokenIssuer: new JwtParticipantTokenIssuer(
                    secret: $this->config->getParticipantTokenSecret(),
                    ttlSeconds: $this->config->getParticipantTokenTtlSeconds(),
                ),
                transactionManager: new PdoTransactionManager($this->database),
            ),
            avatarCatalog: $avatarCatalog,
            responseFactory: new ResponseFactory(),
        );
    }

    public function createStudentController(): StudentController
    {
        $studentRepository = new MySqlStudentRepository($this->database);
        $auditLogRepository = new MySqlAuditLogRepository($this->database);

        return new StudentController(
            studentService: new StudentService(
                students: $studentRepository,
                auditLogService: new AuditLogService($auditLogRepository),
                transactionManager: new PdoTransactionManager($this->database),
            ),
            responseFactory: new ResponseFactory(),
        );
    }

    public function createStudentStatisticsController():
        StudentStatisticsController
    {
        $studentRepository = new MySqlStudentRepository($this->database);
        $statisticsRepository = new MySqlStudentStatisticsRepository(
            $this->database,
        );
        $statisticsAssembler = new StudentStatisticsAssembler(
            statistics: $statisticsRepository,
        );

        return new StudentStatisticsController(
            statisticsService: new StudentStatisticsService(
                students: $studentRepository,
                statistics: $statisticsRepository,
                assembler: $statisticsAssembler,
            ),
            responseFactory: new ResponseFactory(),
        );
    }

    public function createWebSocketGatewayRouter(): WebSocketGatewayRouter
    {
        $sessionRepository = new MySqlQuizSessionRepository($this->database);
        $sessionQuestionRepository = new MySqlSessionQuestionRepository(
            $this->database,
        );
        $participantRepository = new MySqlSessionParticipantRepository(
            $this->database,
        );
        $connectionLimiter = new WebSocketConnectionLimiter(
            globalLimit: $this->config->getWebSocketConnectionLimit(),
            pendingLimit:
                $this->config->getWebSocketPendingConnectionLimit(),
            perIpLimit:
                $this->config->getWebSocketConnectionPerIpLimit(),
        );
        $abuseLimiter = new WebSocketAbuseLimiter(
            authenticationAttemptLimit:
                $this->config->getWebSocketAuthenticationAttemptLimit(),
            authenticationIpAttemptLimit:
                $this->config->getWebSocketAuthenticationIpAttemptLimit(),
            authenticationIpWindowSeconds:
                $this->config->getWebSocketAuthenticationIpWindowSeconds(),
            answerAttemptLimit:
                $this->config->getWebSocketAnswerAttemptLimit(),
            answerAttemptWindowSeconds:
                $this->config->getWebSocketAnswerAttemptWindowSeconds(),
        );

        return new WebSocketGatewayRouter(
            participantGateway: new ParticipantWebSocketGateway(
                participantConnectionService: new ParticipantConnectionService(
                    participantTokenVerifier: new JwtParticipantTokenVerifier(
                        secret: $this->config->getParticipantTokenSecret(),
                    ),
                    sessions: $sessionRepository,
                    participants: $participantRepository,
                    sessionQuestions: $sessionQuestionRepository,
                    answers: new MySqlParticipantAnswerRepository(
                        $this->database,
                    ),
                    publicQuestionMapper: new PublicSessionQuestionMapper(),
                    transactionManager: new PdoTransactionManager(
                        $this->database,
                    ),
                    closedQuestionResultAssembler:
                        $this->closedQuestionResultAssembler,
                    finalResultAssembler:
                        $this->finalQuizSessionResultAssembler,
                ),
                answerSubmissionService: new AnswerSubmissionService(
                    sessions: $sessionRepository,
                    sessionQuestions: $sessionQuestionRepository,
                    participants: $participantRepository,
                    answers: new MySqlParticipantAnswerRepository(
                        $this->database,
                    ),
                    scoreCalculator: new AnswerScoreCalculator(),
                    transactionManager: new PdoTransactionManager(
                        $this->database,
                    ),
                ),
                connectionRegistry: $this->participantConnectionRegistry,
                messageEncoder: $this->webSocketMessageEncoder,
                payloadMapper: $this->sessionWebSocketPayloadMapper,
                connectionLimiter: $connectionLimiter,
                abuseLimiter: $abuseLimiter,
            ),
            echoGateway: new EchoGateway(),
            messageEncoder: $this->webSocketMessageEncoder,
            originPolicy: new WebSocketOriginPolicy(
                $this->config->getWebSocketAllowedOrigins(),
                requireHttps: $this->config->getAppEnv() === 'production',
            ),
            framePolicy: new WebSocketFramePolicy(
                $this->config->getWebSocketGameplayMaximumFrameBytes(),
            ),
            connectionLimiter: $connectionLimiter,
            abuseLimiter: $abuseLimiter,
            clientAddress: new ClientAddress(),
            routePolicy: new WebSocketRoutePolicy(
                echoEnabled: $this->config->getAppEnv() === 'development',
            ),
        );
    }

    public function createChangePasswordController(): ChangePasswordController
    {
        $userRepository = new MySqlUserRepository($this->database);
        $refreshTokenRepository = new MySqlRefreshTokenRepository(
            $this->database,
        );
        $auditLogRepository = new MySqlAuditLogRepository(
            $this->database,
        );

        return new ChangePasswordController(
            userService: new UserService(
                users: $userRepository,
                passwordHasher: new BcryptPasswordHasher(),
                refreshTokens: $refreshTokenRepository,
                auditLogService: new AuditLogService(
                    $auditLogRepository,
                ),
                transactionManager: new PdoTransactionManager(
                    $this->database,
                ),
            ),
            authCookieService: $this->createAuthCookieService(),
            responseFactory: new ResponseFactory(),
        );
    }

    private function createAuthService(): AuthService
    {
        $userRepository = new MySqlUserRepository($this->database);
        $refreshTokenRepository = new MySqlRefreshTokenRepository($this->database);
        $loginAttemptRepository = new MySqlLoginAttemptRepository($this->database);
        $auditLogRepository = new MySqlAuditLogRepository($this->database);

        return new AuthService(
            users: $userRepository,
            passwordHasher: new BcryptPasswordHasher(),
            jwtService: new JwtTokenService(
                config: $this->config,
                environment: $this->environment,
            ),
            refreshTokenService: new DatabaseRefreshTokenService(
                refreshTokens: $refreshTokenRepository,
                config: $this->config,
                transactionManager: new PdoTransactionManager($this->database),
            ),
            csrfTokenService: new DefaultCsrfTokenService(),
            loginAttemptService: new LoginAttemptService(
                loginAttempts: $loginAttemptRepository,
                accountAttemptLimit:
                    $this->config->getLoginAttemptLimit(),
                lockDurationMinutes:
                    $this->config->getLoginLockDurationMinutes(),
                ipRateLimiter: new LoginIpRateLimiter(
                    attemptLimit: $this->config->getLoginIpAttemptLimit(),
                    windowSeconds:
                        $this->config->getLoginLockDurationMinutes() * 60,
                ),
            ),
            auditLogService: new AuditLogService($auditLogRepository),
            config: $this->config,
        );
    }

    private function createAuthCookieService(): AuthCookieService
    {
        return new AuthCookieService($this->config);
    }
}
