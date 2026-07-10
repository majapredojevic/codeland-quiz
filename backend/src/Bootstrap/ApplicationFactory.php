<?php

declare(strict_types=1);

namespace CodeLandQuiz\Bootstrap;

use CodeLandQuiz\Auth\AuditLogService;
use CodeLandQuiz\Auth\AuthCookieService;
use CodeLandQuiz\Auth\AuthService;
use CodeLandQuiz\Auth\BcryptPasswordHasher;
use CodeLandQuiz\Auth\DatabaseRefreshTokenService;
use CodeLandQuiz\Auth\DefaultCsrfTokenService;
use CodeLandQuiz\Auth\JwtTokenService;
use CodeLandQuiz\Auth\LoginAttemptService;
use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\Controller\AuthController;
use CodeLandQuiz\Controller\RefreshController;
use CodeLandQuiz\Http\CookieReader;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Repository\MySqlAuditLogRepository;
use CodeLandQuiz\Repository\MySqlLoginAttemptRepository;
use CodeLandQuiz\Repository\MySqlRefreshTokenRepository;
use CodeLandQuiz\Repository\MySqlUserRepository;
use CodeLandQuiz\Support\Database;
use CodeLandQuiz\Support\Environment;

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
