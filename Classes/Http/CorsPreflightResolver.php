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

namespace KonradMichalik\Typo3Routing\Http;

use KonradMichalik\Typo3Routing\Routing\{RouteMatcher, RouteRegistry, SiteLanguageScope};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Symfony\Component\Routing\Exception\{MethodNotAllowedException, ResourceNotFoundException};
use Symfony\Component\Routing\RequestContext;

use function array_values;

/**
 * CorsPreflightResolver.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class CorsPreflightResolver
{
    public function __construct(
        private RouteRegistry $registry,
        private RouteMatcher $matcher,
        private CorsHandler $cors,
        private SiteLanguageScope $siteLanguageScope,
    ) {}

    /**
     * Returns null when CORS is off (globally and for the matched route), the request is not a
     * preflight, or the path matches nothing (so the caller continues the gauntlet).
     *
     * @throws ResourceNotFoundException the path matches a route that is invisible in the current context
     */
    public function resolve(ServerRequestInterface $request, string $path, RequestContext $context): ?ResponseInterface
    {
        $intendedMethod = $request->getHeaderLine('Access-Control-Request-Method');
        if ('OPTIONS' !== $request->getMethod() || '' === $intendedMethod) {
            return null;
        }

        try {
            // Matched against the *intended* method (Access-Control-Request-Method), not OPTIONS itself:
            // OPTIONS is rarely a declared method, so matching on it would usually only yield a
            // "not allowed" exception with no specific route — matching on the intended method instead
            // resolves exactly the route the real request will hit, and with it its own #[Cors] override.
            $context->setMethod($intendedMethod);
            // Trailing-slash tolerant, like the real request the browser is about to send.
            $match = $this->matcher->match($path, $context);
        } catch (MethodNotAllowedException $exception) {
            // No route accepts the intended method at all — same fate the real request would meet, so
            // only the global policy applies (there is no specific route to resolve an override from).
            return $this->cors->isEnabled() ? $this->cors->preflightResponse(array_values($exception->getAllowedMethods()), $request) : null;
        } catch (ResourceNotFoundException) {
            return null;
        }

        if (!$this->siteLanguageScope->isMatchVisible($match, $request)) {
            // Not null: the caller would go on to answer OPTIONS on the route's path with a 405 and its
            // Allow header, where a missing path gets a 404. The caller must treat it as a missing path.
            throw new ResourceNotFoundException();
        }

        $routeName = (string) ($match['_route'] ?? '');
        $corsConfig = $this->registry->getCorsConfig($routeName);
        if (!$this->cors->isEnabled($corsConfig)) {
            return null;
        }

        $methods = $this->registry->getRoutes()[$routeName]['methods'] ?? [];

        return $this->cors->preflightResponse($methods, $request, $corsConfig);
    }
}
