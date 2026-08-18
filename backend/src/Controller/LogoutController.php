<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\Auth\AuthCookieService;
use CodeLandQuiz\Auth\RefreshTokenService;
use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\Http\CookieReader;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Throwable;

final class LogoutController
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokenService,
        private readonly AuthCookieService $authCookieService,
        private readonly CookieReader $cookieReader,
        private readonly AppConfig $config,
        private readonly ResponseFactory $responseFactory,
    ) {
    }

    public function __invoke(
        Request $request,
        Response $response,
        RequestContext $context,
    ): void {
        try {
            $refreshToken = $this->cookieReader->getOptionalCookie(
                $request,
                $this->config->getRefreshTokenCookieName(),
            );

            if ($refreshToken !== null) {
                $this->refreshTokenService->revoke($refreshToken);
            }

            $this->authCookieService->clearAuthenticationCookies($response);

            $this->responseFactory->noContent($response);
        } catch (Throwable) {
            $this->authCookieService->clearAuthenticationCookies($response);
            $this->responseFactory->error(
                $response,
                'Internal server error.',
                500,
            );
        }
    }
}
