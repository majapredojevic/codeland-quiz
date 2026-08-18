<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\Auth\AuthCookieService;
use CodeLandQuiz\Auth\AuthService;
use CodeLandQuiz\Auth\Exception\InvalidCredentialsException;
use CodeLandQuiz\Auth\Exception\LoginRateLimitedException;
use CodeLandQuiz\Auth\LoginInputNormalizer;
use CodeLandQuiz\DTO\LoginDTO;
use CodeLandQuiz\DTO\LoginResult;
use CodeLandQuiz\Http\JsonRequest;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Support\ClientAddress;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Throwable;

final class AuthController
{
    private const CSRF_HEADER_NAME = 'X-CSRF-Token';

    public function __construct(
        private readonly AuthService $authService,
        private readonly AuthCookieService $authCookieService,
        private readonly ResponseFactory $responseFactory,
        private readonly LoginInputNormalizer $inputNormalizer,
        private readonly ClientAddress $clientAddress,
    ) {
    }

    public function __invoke(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $body = JsonRequest::from($request);

            $loginResult = $this->authService->login(
                new LoginDTO(
                    email: $this->inputNormalizer->email(
                        $body->getString('email'),
                    ),
                    password: $body->getString('password'),
                ),
                $this->inputNormalizer->userAgent(
                    $request->header['user-agent'] ?? null,
                ),
                $this->clientAddress->identifier(
                    $request->server['remote_addr'] ?? null,
                ),
            );

            $this->authCookieService->setAuthenticationCookies(
                response: $response,
                accessToken: $loginResult->accessToken,
                refreshToken: $loginResult->refreshToken,
            );

            $this->authCookieService->setCsrfCookie(
                $response,
                $loginResult->csrfToken,
            );

            $response->header(self::CSRF_HEADER_NAME, $loginResult->csrfToken);

            $this->responseFactory->json(
                $response,
                $this->createLoginResponse($loginResult),
            );
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                400,
            );
        } catch (InvalidCredentialsException) {
            $this->responseFactory->error(
                $response,
                'Email ili lozinka nisu ispravni.',
                401,
            );
        } catch (LoginRateLimitedException $exception) {
            $response->header(
                'Retry-After',
                (string) $exception->getRetryAfterSeconds(),
            );
            $this->responseFactory->error(
                $response,
                'Previše neuspješnih pokušaja. Pokušajte ponovo kasnije.',
                429,
            );
        } catch (Throwable $throwable) {
            error_log(sprintf(
                'Login infrastructure failure on /api/auth/login [%s].',
                $throwable::class,
            ));
            $this->responseFactory->error(
                $response,
                'Prijava trenutno nije moguća. Pokušajte ponovo.',
                500,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function createLoginResponse(LoginResult $loginResult): array
    {
        return [
            'expiresInSeconds' => $loginResult->expiresInSeconds,
            'user' => [
                'id' => $loginResult->userId,
                'name' => $loginResult->userName,
                'email' => $loginResult->userEmail,
                'role' => $loginResult->userRole->value,
                'mustChangePassword' => $loginResult->mustChangePassword,
            ],
        ];
    }
}
