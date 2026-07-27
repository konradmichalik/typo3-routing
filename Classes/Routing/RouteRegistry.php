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

namespace KonradMichalik\Typo3Routing\Routing;

use LogicException;
use Psr\Container\ContainerInterface;
use Symfony\Component\Routing\Matcher\{CompiledUrlMatcher, UrlMatcher, UrlMatcherInterface};
use Symfony\Component\Routing\{RequestContext, Route as SymfonyRoute, RouteCollection};

/**
 * RouteRegistry.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RouteRegistry
{
    private ?RouteCollection $collection = null;

    /**
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null}> $routes
     * @param array<string, array{lifetime: int, tags: list<string>, ignoreParams: list<string>}>                                                                                                                                               $cacheConfigs
     * @param array<string, array{limit: int, interval: string, policy: string, keyBy: string}>                                                                                                                                                 $rateLimits
     * @param array<string, list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>>                                                                                                     $arguments
     * @param array<string, list<array{service: string, options: array<string, mixed>}>>                                                                                                                                                        $authenticators
     * @param array<string, string>                                                                                                                                                                                                             $requestTokenScopes
     * @param array<mixed>                                                                                                                                                                                                                      $compiledRoutes
     */
    public function __construct(
        private readonly array $routes,
        private readonly ContainerInterface $controllerLocator,
        private readonly array $cacheConfigs = [],
        private readonly array $rateLimits = [],
        private readonly array $arguments = [],
        private readonly array $authenticators = [],
        private readonly array $requestTokenScopes = [],
        private readonly ?ContainerInterface $authenticatorLocator = null,
        private readonly array $compiledRoutes = [],
    ) {}

    /**
     * Builds the Symfony route collection from baked route arrays. Shared by the lazy runtime
     * collection (URL generation, matcher fallback) and the compiler pass, which dumps the same
     * collection into the compiled matcher format at container build time.
     *
     * @internal dispatch/URL-generation plumbing, not part of the metadata surface — see docs/EXTENDING.md
     *
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null}> $routes
     */
    public static function buildCollection(array $routes): RouteCollection
    {
        $collection = new RouteCollection();
        foreach ($routes as $name => $route) {
            $collection->add($name, new SymfonyRoute(
                $route['path'],
                [
                    // User-supplied defaults first; the internal keys are placed last so they can
                    // never be overwritten (the compiler pass already rejects reserved "_" keys).
                    ...($route['defaults'] ?? []),
                    '_controller' => $route['controller'],
                    '_env' => $route['env'],
                    '_requirements' => $route['requirements'],
                ],
                $route['requirements'],
                [],
                $route['host'] ?? '',
                $route['schemes'] ?? [],
                $route['methods'],
            ), $route['priority'] ?? 0);
        }

        return $collection;
    }

    /**
     * @internal dispatch/URL-generation plumbing, not part of the metadata surface — see docs/EXTENDING.md
     */
    public function getRouteCollection(): RouteCollection
    {
        return $this->collection ??= self::buildCollection($this->routes);
    }

    /**
     * Matching prefers the routes pre-compiled at container build time: the plain UrlMatcher would
     * re-compile every route's regex on each request. The fallback covers registries constructed
     * without compiled routes (tests, manual wiring).
     *
     * @internal dispatch plumbing, not part of the metadata surface — see docs/EXTENDING.md
     */
    public function getMatcher(RequestContext $context): UrlMatcherInterface
    {
        if ([] !== $this->compiledRoutes) {
            return new CompiledUrlMatcher($this->compiledRoutes, $context);
        }

        return new UrlMatcher($this->getRouteCollection(), $context);
    }

    /**
     * @internal dispatch plumbing, not part of the metadata surface — see docs/EXTENDING.md
     */
    public function getControllerLocator(): ContainerInterface
    {
        return $this->controllerLocator;
    }

    /**
     * @internal dispatch plumbing, not part of the metadata surface — see docs/EXTENDING.md
     */
    public function getAuthenticatorLocator(): ContainerInterface
    {
        // Always populated by the compiler pass; only ever reached for routes that declare authenticators.
        return $this->authenticatorLocator ?? throw new LogicException('No authenticator locator was provided to the route registry.', 1750000021);
    }

    /**
     * The route's authenticators (OR-combined). An empty list means the route is public.
     *
     * @return list<array{service: string, options: array<string, mixed>}>
     */
    public function getAuthenticators(string $routeName): array
    {
        return $this->authenticators[$routeName] ?? [];
    }

    /**
     * The expected request-token scope for the route, or null when CSRF protection is not opted in.
     */
    public function getRequestTokenScope(string $routeName): ?string
    {
        return $this->requestTokenScopes[$routeName] ?? null;
    }

    /**
     * @return array{lifetime: int, tags: list<string>, ignoreParams: list<string>}|null
     */
    public function getCacheConfig(string $routeName): ?array
    {
        return $this->cacheConfigs[$routeName] ?? null;
    }

    /**
     * @return array{limit: int, interval: string, policy: string, keyBy: string}|null
     */
    public function getRateLimit(string $routeName): ?array
    {
        return $this->rateLimits[$routeName] ?? null;
    }

    /**
     * The controller method's parameters in declaration order, as resolved at compile time.
     * An empty list means the method takes no arguments (or has no recorded spec).
     *
     * @return list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>
     */
    public function getArguments(string $routeName): array
    {
        return $this->arguments[$routeName] ?? [];
    }

    /**
     * @return array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
