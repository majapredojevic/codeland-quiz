<?php

declare(strict_types=1);

namespace CodeLandQuiz\Middleware;

use CodeLandQuiz\Auth\JwtService;
use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\Http\CookieReader;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use InvalidArgumentException;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use RuntimeException;
use Throwable;

final class AuthenticationMiddleware
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly CookieReader $cookieReader,
        private readonly AppConfig $config,
        private readonly ResponseFactory $responseFactory,
    ) {}

    /**
     * @param callable(Request, Response, RequestContext): void $next
     */
    public function handle(
        Request $request,
        Response $response,
        RequestContext $context,
        callable $next,
    ): void {
        try {
            $accessToken = $this->cookieReader->getCookie(
                $request,
                $this->config->getAccessTokenCookieName(),
            );

            $context->setAuthenticatedUser(
                $this->jwtService->decodeAccessToken($accessToken),
            );

            $next($request, $response, $context);
        } catch (InvalidArgumentException | RuntimeException) {
            $this->responseFactory->error($response, 'Authentication required.', 401);
        } catch (Throwable) {
            $this->responseFactory->error($response, 'Internal server error.', 500);
        }
    }
}
