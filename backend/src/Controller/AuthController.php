<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\Auth\AuthCookieService;
use CodeLandQuiz\Auth\AuthService;
use CodeLandQuiz\DTO\LoginDTO;
use CodeLandQuiz\DTO\LoginResult;
use CodeLandQuiz\Http\JsonRequest;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use RuntimeException;
use Throwable;

final class AuthController
{
    private const CSRF_HEADER_NAME = 'X-CSRF-Token';

    public function __construct(
        private readonly AuthService $authService,
        private readonly AuthCookieService $authCookieService,
        private readonly ResponseFactory $responseFactory,
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
                    email: $body->getString('email'),
                    password: $body->getString('password'),
                ),
                $this->userAgent($request),
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
        } catch (RuntimeException $exception) {
            $this->responseFactory->error(
                $response,
                $exception->getMessage(),
                401,
            );
        } catch (Throwable) {
            $this->responseFactory->error(
                $response,
                'Internal server error.',
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

    private function userAgent(Request $request): ?string
    {
        $userAgent = $request->header['user-agent'] ?? null;

        if (!is_string($userAgent) || $userAgent === '') {
            return null;
        }

        return $userAgent;
    }
}
