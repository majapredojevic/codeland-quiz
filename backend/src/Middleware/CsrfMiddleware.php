<?php

declare(strict_types=1);

namespace CodeLandQuiz\Middleware;

use CodeLandQuiz\Auth\CsrfTokenService;
use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\Http\CookieReader;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Throwable;

final readonly class CsrfMiddleware
{
    private const CSRF_HEADER_NAME = 'x-csrf-token';

    public function __construct(
        private CsrfTokenService $csrfTokenService,
        private CookieReader $cookieReader,
        private AppConfig $config,
        private ResponseFactory $responseFactory,
    ) {
    }

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
            $headerToken = $this->csrfHeader($request);

            if ($headerToken === null) {
                $this->deny($response);

                return;
            }

            $cookieToken = $this->cookieReader->getCookie(
                $request,
                $this->config->getCsrfTokenCookieName(),
            );

            if (!$this->csrfTokenService->validate($headerToken, $cookieToken)) {
                $this->deny($response);

                return;
            }

            $next($request, $response, $context);
        } catch (Throwable) {
            $this->deny($response);
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

    private function deny(Response $response): void
    {
        $this->responseFactory->error(
            $response,
            'Invalid CSRF token.',
            403,
        );
    }
}