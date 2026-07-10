<?php

declare(strict_types=1);

namespace CodeLandQuiz\Controller;

use CodeLandQuiz\Auth\AuthCookieService;
use CodeLandQuiz\Auth\RefreshTokenService;
use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\Http\CookieReader;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use RuntimeException;
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
            $refreshToken = $this->cookieReader->getCookie(
                $request,
                $this->config->getRefreshTokenCookieName(),
            );

            $this->refreshTokenService->revoke($refreshToken);
            $this->authCookieService->clearAuthenticationCookies($response);

            $response->status(204);
            $response->end();
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
}