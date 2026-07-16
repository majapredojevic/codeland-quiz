<?php

declare(strict_types=1);

namespace CodeLandQuiz\Middleware;

use CodeLandQuiz\Auth\JwtService;
use CodeLandQuiz\Config\AppConfig;
use CodeLandQuiz\DTO\CurrentUserDTO;
use CodeLandQuiz\Http\CookieReader;
use CodeLandQuiz\Http\RequestContext;
use CodeLandQuiz\Http\ResponseFactory;
use CodeLandQuiz\Repository\UserRepository;
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
        private readonly UserRepository $users,
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
            $accessToken = $this->cookieReader->getCookie(
                $request,
                $this->config->getAccessTokenCookieName(),
            );

            $payload = $this->jwtService->decodeAccessToken($accessToken);
            $user = $this->users->findById($payload->userId);

            if ($user === null) {
                throw new RuntimeException('Authenticated user was not found.');
            }

            if (!$user->isActive()) {
                throw new RuntimeException('Authenticated user is inactive.');
            }

            if (!$user->canUseNormalLogin()) {
                throw new RuntimeException(
                    'Authenticated user cannot use normal login.',
                );
            }

            $context->setAuthenticatedUser($payload);
            $context->setCurrentUser(
                new CurrentUserDTO(
                    id: $user->getId(),
                    name: $user->getName(),
                    email: $user->getEmail(),
                    role: $user->getRole(),
                    mustChangePassword: $user->mustChangePassword(),
                ),
            );
        } catch (InvalidArgumentException | RuntimeException) {
            $this->responseFactory->error(
                $response,
                'Authentication required.',
                401,
            );

            return;
        } catch (Throwable) {
            $this->responseFactory->error(
                $response,
                'Internal server error.',
                500,
            );

            return;
        }

        $next($request, $response, $context);
    }
}
