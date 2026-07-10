<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\Auth\AuthCookieService;
use CodeLandQuiz\Auth\CsrfTokenService;
use CodeLandQuiz\Auth\RefreshTokenService;
use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\Http\CookieReader;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Http\RequestContext;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use RuntimeException;
use Throwable;

final class LogoutController
{
    private const CSRF_HEADER_NAME = 'x-csrf-token';

    public function __construct(
        private readonly RefreshTokenService $refreshTokenService,
        private readonly AuthCookieService $authCookieService,
        private readonly CsrfTokenService $csrfTokenService,
        private readonly CookieReader $cookieReader,
        private readonly AppConfig $config,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function __invoke(Request $request, Response $response, RequestContext $context): void
    {
        try {
            $csrfHeader = $this->csrfHeader($request);

            if ($csrfHeader === null) {
                $this->responseFactory->error($response, 'Invalid CSRF token.', 403);

                return;
            }

            $csrfCookie = $this->cookieReader->getCookie(
                $request,
                $this->config->getCsrfTokenCookieName(),
            );

            if (!$this->csrfTokenService->validate($csrfHeader, $csrfCookie)) {
                $this->responseFactory->error($response, 'Invalid CSRF token.', 403);

                return;
            }

            $refreshToken = $this->cookieReader->getCookie(
                $request,
                $this->config->getRefreshTokenCookieName(),
            );

            $this->refreshTokenService->revoke($refreshToken);
            $this->authCookieService->clearAuthenticationCookies($response);

            $response->status(204);
            $response->end();
        } catch (InvalidArgumentException $exception) {
            $this->responseFactory->error($response, $exception->getMessage(), 400);
        } catch (RuntimeException $exception) {
            $this->responseFactory->error($response, $exception->getMessage(), 401);
        } catch (Throwable) {
            $this->responseFactory->error($response, 'Internal server error.', 500);
        }
    }

    private function csrfHeader(Request $request): ?string
    {
        $value = $request->header[self::CSRF_HEADER_NAME] ?? null;

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
