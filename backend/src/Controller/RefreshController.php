<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\Auth\AuthCookieService;
use CodeLandQuiz\Auth\AuthService;
use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\Http\CookieReader;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Http\RequestContext;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use RuntimeException;
use Throwable;

final class RefreshController
{
    private const CSRF_HEADER_NAME = 'X-CSRF-Token';

    public function __construct(
        private readonly AuthService $authService,
        private readonly AuthCookieService $authCookieService,
        private readonly ResponseFactory $responseFactory,
        private readonly CookieReader $cookieReader,
        private readonly AppConfig $config,
    ) {}

    public function __invoke(Request $request, Response $response, RequestContext $context): void
    {
        try {
            $refreshToken = $this->cookieReader->getCookie(
                $request,
                $this->config->getRefreshTokenCookieName(),
            );

            $refreshResult = $this->authService->refresh($refreshToken);

            $this->authCookieService->setAuthenticationCookies(
                response: $response,
                accessToken: $refreshResult->accessToken,
                refreshToken: $refreshResult->refreshToken,
            );

            $this->authCookieService->setCsrfCookie(
                $response,
                $refreshResult->csrfToken,
            );

            $response->header(self::CSRF_HEADER_NAME, $refreshResult->csrfToken);

            $this->responseFactory->json($response, [
                'expiresInSeconds' => $refreshResult->expiresInSeconds,
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error($response, $exception->getMessage(), 400);
        } catch (RuntimeException $exception) {
            $this->responseFactory->error($response, $exception->getMessage(), 401);
        } catch (Throwable) {
            $this->responseFactory->error($response, 'Internal server error.', 500);
        }
    }
}
