<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_routing" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3Routing\Authentication;

use KonradMichalik\Typo3Routing\Http\JsonErrorResponse;
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Core\Context\{Context, SecurityAspect};
use TYPO3\CMS\Core\Security\RequestToken;

use function assert;
use function in_array;

/**
 * AccessGuard.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class AccessGuard
{
    public function __construct(
        private RouteRegistry $registry,
        private Context $context,
    ) {}

    /**
     * @param array<string, mixed> $match
     *
     * @return ResponseInterface|null the error response when access is denied, or null when granted
     */
    public function enforce(array $match, ServerRequestInterface $request): ?ResponseInterface
    {
        $routeName = (string) ($match['_route'] ?? '');

        if (!$this->isAuthenticated($routeName, $request)) {
            return JsonErrorResponse::create(401, 'Unauthorized');
        }

        if (!$this->passesRequestToken($routeName, $request)) {
            return JsonErrorResponse::create(403, 'Forbidden');
        }

        return null;
    }

    private function isAuthenticated(string $routeName, ServerRequestInterface $request): bool
    {
        $authenticators = $this->registry->getAuthenticators($routeName);
        if ([] === $authenticators) {
            return true;
        }

        foreach ($authenticators as $authenticator) {
            $service = $this->registry->getAuthenticatorLocator()->get($authenticator['service']);
            assert($service instanceof RouteAuthenticatorInterface);
            if ($service->authenticate($request, $authenticator['options'])) {
                return true;
            }
        }

        return false;
    }

    private function passesRequestToken(string $routeName, ServerRequestInterface $request): bool
    {
        $scope = $this->registry->getRequestTokenScope($routeName);

        // Not opted in, or a non-state-changing method that is not CSRF-relevant → nothing to verify.
        if (null === $scope || !in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            return true;
        }

        // The core RequestTokenMiddleware has already decoded the received token into the SecurityAspect:
        // null = none sent, false = present but not verifiable against the nonce, otherwise the token object.
        $token = SecurityAspect::provideIn($this->context)->getReceivedRequestToken();

        return $token instanceof RequestToken && $token->scope === $scope;
    }
}
