<?php

declare(strict_types=1);

use CodeLandQuiz\Auth\Exception\InvalidCredentialsException;
use CodeLandQuiz\Auth\Exception\LoginRateLimitedException;
use CodeLandQuiz\Auth\LoginAttemptService;
use CodeLandQuiz\Auth\LoginInputNormalizer;
use CodeLandQuiz\Auth\LoginIpRateLimiter;
use CodeLandQuiz\Model\LoginAttempt;
use CodeLandQuiz\Model\ParticipantType;
use CodeLandQuiz\Repository\LoginAttemptRepository;
use CodeLandQuiz\Support\ClientAddress;
use CodeLandQuiz\WebSocket\AuthenticatedParticipantConnection;
use CodeLandQuiz\WebSocket\Exception\WebSocketRateLimitExceededException;
use CodeLandQuiz\WebSocket\ParticipantConnectionRegistry;
use CodeLandQuiz\WebSocket\WebSocketAbuseLimiter;
use CodeLandQuiz\WebSocket\WebSocketConnectionLimiter;
use CodeLandQuiz\WebSocket\WebSocketFramePolicy;
use CodeLandQuiz\WebSocket\WebSocketOriginPolicy;
use CodeLandQuiz\WebSocket\WebSocketRoutePolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertSecurity(mixed $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param class-string<Throwable> $exceptionClass
 * @param callable(): void $operation
 */
function assertSecurityThrows(
    string $exceptionClass,
    callable $operation,
    string $message,
): void {
    try {
        $operation();
    } catch (Throwable $throwable) {
        if ($throwable instanceof $exceptionClass) {
            return;
        }

        throw $throwable;
    }

    throw new RuntimeException($message);
}

final class InMemoryLoginAttemptRepository implements LoginAttemptRepository
{
    /**
     * @var LoginAttempt[]
     */
    public array $attempts = [];

    public int $synchronizations = 0;

    public function synchronizedByEmail(
        string $email,
        callable $operation,
    ): mixed {
        $this->synchronizations++;

        return $operation();
    }

    public function save(LoginAttempt $attempt): void
    {
        $this->attempts[] = $attempt;
    }

    public function countFailedAttemptsSince(
        string $email,
        DateTimeImmutable $since,
    ): int {
        return count(array_filter(
            $this->attempts,
            static fn (LoginAttempt $attempt): bool =>
                $attempt->getEmail() === $email
                && !$attempt->isSuccessful()
                && $attempt->getAttemptedAt() >= $since,
        ));
    }

    public function clearAttempts(string $email): void
    {
        $this->attempts = array_values(array_filter(
            $this->attempts,
            static fn (LoginAttempt $attempt): bool =>
                $attempt->getEmail() !== $email,
        ));
    }
}

$normalizer = new LoginInputNormalizer();
$oversizedUserAgent = $normalizer->userAgent(str_repeat('A', 4096));
assertSecurity(
    is_string($oversizedUserAgent) && strlen($oversizedUserAgent) === 255,
    'Oversized User-Agent was not safely bounded to the DB column.',
);
assertSecurity(
    $normalizer->userAgent(null) === null,
    'Missing User-Agent was not normalized to null.',
);
assertSecurity(
    $normalizer->email('  TEACHER@EXAMPLE.COM ') === 'teacher@example.com',
    'Login email was not normalized.',
);
assertSecurityThrows(
    InvalidArgumentException::class,
    fn () => $normalizer->email(str_repeat('a', 181) . '@example.com'),
    'Oversized login email was accepted.',
);

$clientAddress = new ClientAddress(['172.30.0.10/32']);
$directAddress = bin2hex((string) inet_pton('203.0.113.25'));
$forwardedAddress = bin2hex((string) inet_pton('198.51.100.42'));
assertSecurity(
    $clientAddress->identifier('203.0.113.25', '198.51.100.42')
        === $directAddress,
    'A non-trusted peer was able to spoof X-Real-IP.',
);
assertSecurity(
    $clientAddress->identifier('172.30.0.10', '198.51.100.42')
        === $forwardedAddress,
    'The configured reverse proxy did not provide the real client address.',
);
assertSecurity(
    $clientAddress->identifier('172.30.0.10', 'not-an-ip')
        === bin2hex((string) inet_pton('172.30.0.10')),
    'A malformed proxy-provided address did not safely fall back to the peer.',
);
assertSecurityThrows(
    InvalidArgumentException::class,
    fn () => new ClientAddress(['172.30.0.10/129']),
    'An invalid trusted-proxy CIDR was accepted.',
);

$timestamp = strtotime('2026-08-18T12:00:00+00:00');
assertSecurity(is_int($timestamp), 'Verification clock could not be created.');
$dateClock = static function () use (&$timestamp): DateTimeImmutable {
    return (new DateTimeImmutable())->setTimestamp($timestamp);
};
$integerClock = static function () use (&$timestamp): int {
    return $timestamp;
};
$attempts = new InMemoryLoginAttemptRepository();
$loginLimiter = new LoginAttemptService(
    loginAttempts: $attempts,
    accountAttemptLimit: 5,
    lockDurationMinutes: 15,
    ipRateLimiter: new LoginIpRateLimiter(100, 900, $integerClock),
    clock: $dateClock,
);
$email = 'teacher@example.com';

for ($index = 0; $index < 5; $index++) {
    assertSecurityThrows(
        InvalidCredentialsException::class,
        fn () => $loginLimiter->executeLoginAttempt(
            $email,
            'school-ip',
            function () use ($loginLimiter, $email, $oversizedUserAgent): never {
                $loginLimiter->recordFailure($email, $oversizedUserAgent);

                throw new InvalidCredentialsException();
            },
        ),
        'Expected invalid login failure was not preserved.',
    );
}

$credentialWorkInvoked = false;
assertSecurityThrows(
    LoginRateLimitedException::class,
    fn () => $loginLimiter->executeLoginAttempt(
        $email,
        'school-ip',
        function () use (&$credentialWorkInvoked): void {
            $credentialWorkInvoked = true;
        },
    ),
    'Account threshold did not reject the next login.',
);
assertSecurity(
    !$credentialWorkInvoked && $attempts->synchronizations === 6,
    'Account threshold was not checked inside serialized account work.',
);
assertSecurity(
    strlen((string) $attempts->attempts[0]->getUserAgent()) === 255,
    'Bounded User-Agent was not retained by attempt persistence.',
);
$timestamp += 901;
assertSecurity(
    $loginLimiter->executeLoginAttempt(
        $email,
        'school-ip',
        static fn (): string => 'allowed',
    ) === 'allowed',
    'Account limiter did not reset after its configured window.',
);

for ($index = 0; $index < 50; $index++) {
    assertSecurity(
        $loginLimiter->executeLoginAttempt(
            sprintf('teacher-%d@example.com', $index),
            'shared-school-ip',
            static fn (): bool => true,
        ),
        'Normal successful logins were blocked on a shared school IP.',
    );
}

$ipTimestamp = 1_000;
$ipLimiter = new LoginIpRateLimiter(
    3,
    60,
    static function () use (&$ipTimestamp): int {
        return $ipTimestamp;
    },
);
$ipLimiter->reserve('client');
$ipLimiter->reserve('client');
$ipLimiter->reserve('client');
assertSecurityThrows(
    LoginRateLimitedException::class,
    fn () => $ipLimiter->reserve('client'),
    'IP threshold did not reject excess failures.',
);
$ipTimestamp += 61;
$ipLimiter->reserve('client');

$originPolicy = new WebSocketOriginPolicy([
    'https://quiz.example.com',
    'http://localhost:4200',
]);
assertSecurity(
    $originPolicy->allows('https://quiz.example.com'),
    'Trusted production Origin was rejected.',
);
assertSecurity(
    $originPolicy->allows('http://localhost:4200'),
    'Explicit development Origin was rejected.',
);
assertSecurity(
    !$originPolicy->allows('https://attacker.example'),
    'Attacker Origin was accepted.',
);
assertSecurity(
    !$originPolicy->allows('https://quiz.example.com/path')
        && !$originPolicy->allows('not-an-origin')
        && !$originPolicy->allows(null),
    'Malformed or missing Origin was accepted.',
);
assertSecurityThrows(
    InvalidArgumentException::class,
    fn () => new WebSocketOriginPolicy(
        ['http://localhost:4200'],
        requireHttps: true,
    ),
    'Production WebSocket policy accepted a non-HTTPS Origin.',
);

$framePolicy = new WebSocketFramePolicy(16_384);
assertSecurity($framePolicy->allows(str_repeat('x', 16_384)), 'Valid frame was rejected.');
assertSecurity(!$framePolicy->allows(str_repeat('x', 16_385)), 'Oversized frame was accepted.');

$connectionLimiter = new WebSocketConnectionLimiter(3, 2, 2);
$connectionLimiter->register(1, 'school-a', true);
$connectionLimiter->register(2, 'school-a', true);
assertSecurityThrows(
    WebSocketRateLimitExceededException::class,
    fn () => $connectionLimiter->register(3, 'school-a', true),
    'Per-IP WebSocket ceiling was not enforced.',
);
$connectionLimiter->markAuthenticated(1);
$connectionLimiter->register(3, 'school-b', true);
assertSecurityThrows(
    WebSocketRateLimitExceededException::class,
    fn () => $connectionLimiter->register(4, 'school-c', false),
    'Global WebSocket ceiling was not enforced.',
);
$connectionLimiter->remove(2);
$connectionLimiter->register(4, 'school-c', true);

$wsTimestamp = 2_000;
$abuseLimiter = new WebSocketAbuseLimiter(
    authenticationAttemptLimit: 3,
    authenticationIpAttemptLimit: 4,
    authenticationIpWindowSeconds: 60,
    answerAttemptLimit: 3,
    answerAttemptWindowSeconds: 10,
    clock: static function () use (&$wsTimestamp): int {
        return $wsTimestamp;
    },
);
$abuseLimiter->registerConnection(10, 'school-a');
$abuseLimiter->recordAuthenticationAttempt(10);
$abuseLimiter->recordAuthenticationAttempt(10);
$abuseLimiter->recordAuthenticationAttempt(10);
assertSecurityThrows(
    WebSocketRateLimitExceededException::class,
    fn () => $abuseLimiter->recordAuthenticationAttempt(10),
    'Per-connection WebSocket authentication limit was not enforced.',
);
$abuseLimiter->registerConnection(11, 'school-a');
$abuseLimiter->recordAuthenticationAttempt(11);
assertSecurityThrows(
    WebSocketRateLimitExceededException::class,
    fn () => $abuseLimiter->recordAuthenticationAttempt(11),
    'Per-IP WebSocket authentication window was not enforced.',
);
$abuseLimiter->markAuthenticated(10);
$abuseLimiter->recordAnswerAttempt(10);
$abuseLimiter->recordAnswerAttempt(10);
$abuseLimiter->recordAnswerAttempt(10);
assertSecurityThrows(
    WebSocketRateLimitExceededException::class,
    fn () => $abuseLimiter->recordAnswerAttempt(10),
    'Answer submission limit was not enforced.',
);
$wsTimestamp += 11;
$abuseLimiter->recordAnswerAttempt(10);
$abuseLimiter->removeConnection(10);

$expiresAt = (new DateTimeImmutable())->setTimestamp(3_000);
$connection = new AuthenticatedParticipantConnection(
    fileDescriptor: 20,
    connectionId: 'connection-id',
    participantId: 7,
    sessionId: 9,
    participantType: ParticipantType::GUEST,
    studentId: null,
    participantTokenExpiresAt: $expiresAt,
);
assertSecurity(!$connection->tokenHasExpired(2_999), 'Valid participant token was expired early.');
assertSecurity($connection->tokenHasExpired(3_000), 'Participant token expiry was not enforced.');

$registry = new ParticipantConnectionRegistry();
$connectionId = $registry->registerPending(20);
$registry->authenticate(
    fileDescriptor: 20,
    connectionId: $connectionId,
    participantId: 7,
    sessionId: 9,
    participantType: ParticipantType::GUEST,
    studentId: null,
    participantTokenExpiresAt: $expiresAt,
);
$registry->markAnswerAccepted(20, 1);
assertSecurity(
    $registry->hasAcceptedCurrentAnswer(20),
    'Accepted answer was not retained for short-circuiting.',
);
$registry->clearAcceptedAnswersForSession(9);
assertSecurity(
    !$registry->hasAcceptedCurrentAnswer(20),
    'Answer short-circuit state was not reset for the next question.',
);

assertSecurity(
    (new WebSocketRoutePolicy(true))->routeForUri('/ws/echo')
        === WebSocketRoutePolicy::ECHO,
    'Development echo route was not available.',
);
assertSecurity(
    (new WebSocketRoutePolicy(false))->routeForUri('/ws/echo') === null,
    'Production echo route remained available.',
);

$sourceRoot = dirname(__DIR__) . '/src';
$authControllerSource = file_get_contents($sourceRoot . '/Controller/AuthController.php');
$gatewaySource = file_get_contents($sourceRoot . '/WebSocket/ParticipantWebSocketGateway.php');
$routerSource = file_get_contents($sourceRoot . '/WebSocket/WebSocketGatewayRouter.php');
$userServiceSource = file_get_contents($sourceRoot . '/Auth/UserService.php');
$managementSource = file_get_contents($sourceRoot . '/Admin/UserManagementService.php');
$userRepositorySource = file_get_contents($sourceRoot . '/Repository/MySqlUserRepository.php');
$applicationSource = file_get_contents($sourceRoot . '/Application.php');
$echoSource = file_get_contents($sourceRoot . '/WebSocket/EchoGateway.php');

assertSecurity(
    is_string($authControllerSource)
        && str_contains($authControllerSource, 'Prijava trenutno nije moguća.')
        && !str_contains($authControllerSource, '$throwable->getMessage()'),
    'Login infrastructure errors are not mapped to a generic response/log.',
);
assertSecurity(
    is_string($userServiceSource)
        && str_contains($userServiceSource, 'findByIdForUpdate'),
    'Own-password mutation does not use the locked user path.',
);
assertSecurity(
    is_string($managementSource)
        && substr_count($managementSource, 'findTeacherByIdForUpdate') >= 3
        && is_string($userRepositorySource)
        && substr_count($userRepositorySource, 'FOR UPDATE') >= 2,
    'Staff mutations do not consistently use SELECT FOR UPDATE.',
);
assertSecurity(
    is_string($routerSource)
        && strpos($routerSource, 'framePolicy->allows')
            < strpos($routerSource, 'gateway->message'),
    'WebSocket size check does not precede gateway/business handling.',
);
assertSecurity(
    is_string($gatewaySource)
        && strpos($gatewaySource, 'tokenHasExpired')
            < strpos($gatewaySource, 'readAuthenticatedMessage'),
    'Participant token expiry is not checked before command decoding.',
);
assertSecurity(
    is_string($applicationSource)
        && str_contains($applicationSource, "on('handshake'")
        && !str_contains($applicationSource, "on('open'"),
    'Origin policy is not enforced in the WebSocket handshake path.',
);
assertSecurity(
    is_string($echoSource)
        && str_contains($echoSource, 'strlen($frame->data)')
        && !str_contains($echoSource, 'Received message from'),
    'Development echo logging still includes arbitrary raw frame data.',
);

echo "Security hardening verification passed.\n";
