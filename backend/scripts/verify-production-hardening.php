<?php

declare(strict_types=1);

use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\Support\ClientAddress;
use CodeLandQuiz\Support\Environment;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertProduction(mixed $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param callable(): void $operation
 */
function assertProductionRejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

/**
 * @param array<string, string> $overrides
 */
function productionConfig(array $overrides = []): AppConfig
{
    $values = array_replace([
        'APP_NAME' => 'CodeLand Quiz',
        'APP_ENV' => 'production',
        'APP_URL' => 'https://quiz.example.com',
        'DB_HOST' => 'mysql',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'codeland_quiz',
        'DB_USERNAME' => 'codeland_production',
        'DB_PASSWORD' => 'ePk4upSp3pDT6Sw92xQUvp5y',
        'ACCESS_TOKEN_COOKIE_NAME' => 'codeland_access',
        'REFRESH_TOKEN_COOKIE_NAME' => 'codeland_refresh',
        'COOKIE_PATH' => '/',
        'CSRF_TOKEN_COOKIE_NAME' => 'codeland_csrf',
        'REFRESH_TOKEN_HASH_KEY' => 'veBDdQPq2rhYstpeRZqTVbPzPWHfAcMr',
        'PARTICIPANT_TOKEN_SECRET' => 'KDnSnLyvbuTBfGMffxuY49t8eky7qjkP',
        'PARTICIPANT_TOKEN_TTL_SECONDS' => '86400',
        'JWT_SECRET' => 'sRnJdSgySYJnEFPdvNUcmFuN9pSe8bsU',
        'JWT_ALGORITHM' => 'HS256',
        'JWT_EXPIRATION_MINUTES' => '60',
        'REFRESH_TOKEN_EXPIRATION_DAYS' => '7',
        'CSRF_TOKEN_EXPIRATION_MINUTES' => '120',
        'LOGIN_ATTEMPT_LIMIT' => '5',
        'LOGIN_LOCK_DURATION_MINUTES' => '15',
        'LOGIN_IP_ATTEMPT_LIMIT' => '100',
        'TRUSTED_PROXY_CIDRS' => '172.30.0.10/32',
        'WS_ALLOWED_ORIGINS' => 'https://quiz.example.com',
        'WS_GAMEPLAY_MAX_FRAME_BYTES' => '16384',
        'WS_AUTH_ATTEMPT_LIMIT' => '3',
        'WS_AUTH_IP_ATTEMPT_LIMIT' => '1000',
        'WS_AUTH_IP_WINDOW_SECONDS' => '60',
        'WS_ANSWER_ATTEMPT_LIMIT' => '8',
        'WS_ANSWER_ATTEMPT_WINDOW_SECONDS' => '10',
        'WS_CONNECTION_LIMIT' => '2000',
        'WS_PENDING_CONNECTION_LIMIT' => '750',
        'WS_CONNECTION_PER_IP_LIMIT' => '750',
        'WS_HEARTBEAT_INTERVAL_SECONDS' => '25',
        'WS_STALE_TIMEOUT_SECONDS' => '75',
        'OPENSWOOLE_MAX_CONNECTIONS' => '4096',
        'OPENSWOOLE_MAX_COROUTINES' => '4096',
        'OPENSWOOLE_TRANSPORT_HEARTBEAT_CHECK_INTERVAL_SECONDS' => '30',
        'OPENSWOOLE_TRANSPORT_HEARTBEAT_IDLE_SECONDS' => '120',
        'COOKIE_SECURE' => 'true',
        'COOKIE_HTTP_ONLY' => 'true',
        'COOKIE_SAME_SITE' => 'Strict',
        'MAX_UPLOAD_SIZE_MB' => '5',
        'QUESTION_IMAGE_STORAGE_PATH' => 'storage/question-images',
        'ALLOWED_IMAGE_EXTENSIONS' => 'jpg,jpeg,png,webp',
        'DEFAULT_QUIZ_QUESTION_TIME_LIMIT_SECONDS' => '30',
        'MAXIMUM_NICKNAME_LENGTH' => '100',
        'DEFAULT_PAGE_SIZE' => '10',
        'MAX_PAGE_SIZE' => '20',
    ], $overrides);

    foreach ($values as $name => $value) {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    return new AppConfig(new Environment(dirname(__DIR__)));
}

$config = productionConfig();
assertProduction(
    $config->getTrustedProxyCidrs() === ['172.30.0.10/32'],
    'Trusted proxy CIDRs were not retained by production configuration.',
);
assertProduction(
    $config->getMaximumUploadSizeBytes() === 5 * 1024 * 1024
        && $config->getMaximumUploadPackageLengthBytes() === 6 * 1024 * 1024,
    'Application upload and multipart package limits are not aligned.',
);
assertProduction(
    $config->getWebSocketHeartbeatIntervalSeconds() === 25
        && $config->getWebSocketStaleTimeoutSeconds() === 75
        && $config->getOpenSwooleMaximumConnections() === 4096
        && $config->getOpenSwooleMaximumCoroutines() === 4096,
    'Production OpenSwoole runtime limits were not retained.',
);

foreach ([
    ['COOKIE_SECURE' => 'false'],
    ['COOKIE_HTTP_ONLY' => 'false'],
    ['COOKIE_SAME_SITE' => 'None'],
    ['COOKIE_PATH' => '/api'],
    ['APP_URL' => 'http://quiz.example.com'],
    ['WS_ALLOWED_ORIGINS' => 'http://quiz.example.com'],
    ['WS_ALLOWED_ORIGINS' => 'https://other.example.com'],
    ['TRUSTED_PROXY_CIDRS' => ''],
    ['JWT_SECRET' => 'replace-with-at-least-32-random-characters'],
    ['DB_USERNAME' => 'codeland'],
    ['DB_PASSWORD' => 'secret'],
] as $unsafeOverride) {
    assertProductionRejects(
        fn () => productionConfig($unsafeOverride),
        'An unsafe production configuration was accepted.',
    );
}

assertProductionRejects(
    fn () => productionConfig([
        'JWT_SECRET' => 'veBDdQPq2rhYstpeRZqTVbPzPWHfAcMr',
    ]),
    'Production token secrets were allowed to reuse the same value.',
);

$clientAddress = new ClientAddress($config->getTrustedProxyCidrs());
assertProduction(
    $clientAddress->identifier('172.30.0.10', '198.51.100.8')
        === bin2hex((string) inet_pton('198.51.100.8')),
    'Trusted Nginx did not resolve the proxy-provided client address.',
);
assertProduction(
    $clientAddress->identifier('203.0.113.4', '1.2.3.4')
        === bin2hex((string) inet_pton('203.0.113.4')),
    'A non-trusted direct peer was able to spoof X-Real-IP.',
);

$repositoryRoot = dirname(__DIR__, 2);

if (!is_file($repositoryRoot . '/compose.production.yaml')) {
    echo "Production backend hardening verification passed.\n";

    exit(0);
}

$compose = file_get_contents($repositoryRoot . '/compose.production.yaml');
$nginx = file_get_contents($repositoryRoot . '/docker/nginx/default.conf.template');
$headers = file_get_contents($repositoryRoot . '/docker/nginx/security-headers.conf');
$phpIni = file_get_contents($repositoryRoot . '/docker/php/production.ini');
$backendDockerfile = file_get_contents($repositoryRoot . '/docker/php/Dockerfile.production');
$frontendBuild = file_get_contents($repositoryRoot . '/frontend/angular.json');
$frontendSocket = file_get_contents(
    $repositoryRoot
        . '/frontend/src/app/features/public/player/data-access/player-game.store.ts',
);

assertProduction(
    is_string($compose)
        && !str_contains($compose, 'phpmyadmin')
        && !str_contains($compose, '9501:9501')
        && !str_contains($compose, '3306:3306')
        && str_contains($compose, '80:80')
        && str_contains($compose, '443:443'),
    'Production Compose source exposes an internal service or omits the edge ports.',
);
assertProduction(
    is_string($nginx)
        && str_contains($nginx, 'ssl_protocols TLSv1.2 TLSv1.3;')
        && str_contains($nginx, 'proxy_set_header Upgrade $http_upgrade;')
        && str_contains($nginx, 'proxy_read_timeout 150s;')
        && str_contains($nginx, 'location = /ready')
        && str_contains($nginx, 'location = /internal/metrics')
        && str_contains($nginx, 'client_max_body_size 6m;')
        && substr_count($nginx, 'proxy_pass http://backend:9501;') >= 7
        && !str_contains($nginx, 'proxy_pass http://backend:9501/;'),
    'Nginx TLS, path-preserving proxy, WebSocket or request-size policy is incomplete.',
);
assertProduction(
    is_string($headers)
        && str_contains($headers, "script-src 'self'")
        && str_contains($headers, "style-src 'self' 'unsafe-inline'")
        && !str_contains($headers, "'unsafe-eval'")
        && str_contains($headers, 'X-Content-Type-Options')
        && str_contains($headers, 'Referrer-Policy')
        && str_contains($headers, 'Permissions-Policy')
        && str_contains($headers, 'X-Frame-Options'),
    'Required edge security headers or the constrained CSP are missing.',
);
assertProduction(
    is_string($phpIni)
        && str_contains($phpIni, 'display_errors=Off')
        && str_contains($phpIni, 'log_errors=On')
        && str_contains($phpIni, 'upload_max_filesize=5M')
        && str_contains($phpIni, 'post_max_size=6M'),
    'Production PHP error or upload policy is not aligned.',
);
assertProduction(
    is_string($backendDockerfile)
        && str_contains($backendDockerfile, 'php:8.3.32-cli-trixie')
        && str_contains($backendDockerfile, 'openswoole-${OPENSWOOLE_VERSION}')
        && str_contains($backendDockerfile, 'USER www-data'),
    'Production backend runtime is not pinned or non-root.',
);
assertProduction(
    is_string($frontendBuild)
        && str_contains($frontendBuild, '"inlineCritical": false')
        && is_string($frontendSocket)
        && str_contains($frontendSocket, "location.protocol === 'https:' ? 'wss:' : 'ws:'"),
    'Angular output still requires inline script or does not select WSS on HTTPS.',
);

echo "Production hardening verification passed.\n";
