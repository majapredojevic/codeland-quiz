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
use CodeLandQuiz\Auth\SecureTemporaryPasswordGenerator;
use CodeLandQuiz\Auth\UserService;
use CodeLandQuiz\Admin\UserManagementService;
use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\Controller\AdminUserController;
use CodeLandQuiz\Controller\AuthController;
use CodeLandQuiz\Controller\ChangePasswordController;
use CodeLandQuiz\Controller\LogoutController;
use CodeLandQuiz\Controller\MeController;
use CodeLandQuiz\Controller\RefreshController;
use CodeLandQuiz\Controller\TopicController;
use CodeLandQuiz\Http\CookieReader;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Middleware\AuthenticationMiddleware;
use CodeLandQuiz\Middleware\CsrfMiddleware;
use CodeLandQuiz\Middleware\PasswordChangeRequiredMiddleware;
use CodeLandQuiz\Middleware\RoleMiddleware;
use CodeLandQuiz\Model\UserRole;
use CodeLandQuiz\Repository\MySqlAuditLogRepository;
use CodeLandQuiz\Repository\MySqlLoginAttemptRepository;
use CodeLandQuiz\Repository\MySqlRefreshTokenRepository;
use CodeLandQuiz\Repository\MySqlTopicRepository;
use CodeLandQuiz\Repository\MySqlUserRepository;
use CodeLandQuiz\Support\Database;
use CodeLandQuiz\Support\Environment;
use CodeLandQuiz\Support\PdoTransactionManager;
use CodeLandQuiz\Topic\TopicService;

final class ApplicationFactory
{
    private Environment $environment;

    private AppConfig $config;

    private Database $database;

    public function __construct(string $projectRootPath)
    {
        $this->environment = new Environment($projectRootPath);
        $this->config = new AppConfig($this->environment);
        $this->database = new Database($this->environment);
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

    public function createAuthController(): AuthController
    {
        return new AuthController(
            authService: $this->createAuthService(),
            authCookieService: $this->createAuthCookieService(),
            responseFactory: new ResponseFactory(),
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
            ),
            csrfTokenService: new DefaultCsrfTokenService(),
            loginAttemptService: new LoginAttemptService(
                loginAttempts: $loginAttemptRepository,
                config: $this->config,
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
