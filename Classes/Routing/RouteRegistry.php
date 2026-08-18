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

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;

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
    private ?RouteCollection $caseInsensitiveCollection = null;

    /**
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool, tags?: list<string>, classExclusivePrefix?: string|null}> $routes
     * @param array<string, array{lifetime: int, tags: list<string>, ignoreParams: list<string>}>                                                                                                                                                                                                                                                            $cacheConfigs
     * @param array<string, array{limit: int, interval: string, policy: string, keyBy: string}>                                                                                                                                                                                                                                                              $rateLimits
     * @param array<string, list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>>                                                                                                                                                                                                                  $arguments
     * @param array<string, list<array{service: string, options: array<string, mixed>}>>                                                                                                                                                                                                                                                                     $authenticators
     * @param array<string, string>                                                                                                                                                                                                                                                                                                                          $requestTokenScopes
     * @param array<mixed>                                                                                                                                                                                                                                                                                                                                   $compiledRoutes
     * @param array<string, array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}>                                                                                                                                                                                                         $corsConfigs
     * @param list<string>                                                                                                                                                                                                                                                                                                                                   $staticPrefixes
     * @param array<string, array<string, string>>                                                                                                                                                                                                                                                                                                           $paramDescriptions
     * @param array<string, list<string>>                                                                                                                                                                                                                                                                                                                    $optionalInputs
     * @param list<string>                                                                                                                                                                                                                                                                                                                                   $caseInsensitivePrefixes
     * @param list<string>                                                                                                                                                                                                                                                                                                                                   $classExclusivePrefixes
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
        private readonly array $corsConfigs = [],
        private readonly array $staticPrefixes = [],
        private readonly array $paramDescriptions = [],
        private readonly array $optionalInputs = [],
        private readonly array $caseInsensitivePrefixes = [],
        private readonly array $classExclusivePrefixes = [],
    ) {}

    /**
     * Builds the Symfony route collection from baked route arrays. Shared by the lazy runtime
     * collection (URL generation, matcher fallback) and the compiler pass, which dumps the same
     * collection into the compiled matcher format at container build time.
     *
     * @internal dispatch/URL-generation plumbing, not part of the metadata surface — see docs/EXTENDING.md
     *
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool, tags?: list<string>, classExclusivePrefix?: string|null}> $routes
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
     * Every route path's static leading segment, as computed by symfony's own route compiler. A path
     * starting with a placeholder (`/{slug}`) yields the empty string, which matches every path —
     * correct, since such a route can live anywhere.
     *
     * A prefix ending in a slash contributes its slashless form as well: `RouteMatcher` tolerates that
     * difference, but it never gets the chance if the gate rejects the path first. Widening the gate is
     * safe in either configuration — it is a filter, so a broader prefix only means the matcher gets to
     * decide. The root prefix `/` is left alone: trimming it would leave the empty string, and it
     * already matches every path anyway.
     *
     * @internal dispatch plumbing, not part of the metadata surface — see docs/EXTENDING.md
     *
     * @return list<string>
     */
    public static function staticPrefixes(RouteCollection $collection): array
    {
        $prefixes = [];
        foreach ($collection->all() as $route) {
            $prefix = $route->compile()->getStaticPrefix();
            $prefixes[] = $prefix;

            $slashless = rtrim($prefix, '/');
            if ('' !== $slashless) {
                $prefixes[] = $slashless;
            }
        }

        return array_values(array_unique($prefixes));
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
     * The routes that opted into case-insensitive matching via #[Route(caseInsensitive: true)].
     * Shared by the compiler pass (baking their prefixes) and the registry's own lazy fallback.
     *
     * @internal dispatch plumbing, not part of the metadata surface — see docs/EXTENDING.md
     *
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool, tags?: list<string>, classExclusivePrefix?: string|null}> $routes
     *
     * @return array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool, tags?: list<string>, classExclusivePrefix?: string|null}>
     */
    public static function caseInsensitiveRoutes(array $routes): array
    {
        return array_filter($routes, static fn (array $route): bool => $route['caseInsensitive'] ?? false);
    }

    /**
     * A second matcher over the opted-in routes alone, or null when no route opted in — which is the
     * default, so the whole feature costs nothing until it is used. RouteMatcher consults it only after
     * the primary matcher has already failed.
     *
     * The plain UrlMatcher is deliberate: CompiledUrlMatcherDumper resolves placeholder-free routes
     * through an exact-match table that no regex modifier can reach, so the dumped matcher could never
     * honour CaseInsensitiveRouteCompiler.
     *
     * @internal dispatch plumbing, not part of the metadata surface — see docs/EXTENDING.md
     */
    public function getCaseInsensitiveMatcher(RequestContext $context): ?UrlMatcherInterface
    {
        $collection = $this->getCaseInsensitiveCollection();

        return 0 === $collection->count() ? null : new UrlMatcher($collection, $context);
    }

    /**
     * The opted-in routes' static prefixes, so the dispatcher's path gate lets their differently-cased
     * requests through in the first place. Derived from the standard compilation, never from the
     * case-insensitive one: that compiler empties the prefix, which would open the gate to every path.
     *
     * Baked in at container build time; the fallback mirrors getStaticPrefixes().
     *
     * @internal dispatch plumbing, not part of the metadata surface — see docs/EXTENDING.md
     *
     * @return list<string>
     */
    public function getCaseInsensitivePrefixes(): array
    {
        if ([] !== $this->caseInsensitivePrefixes || [] === $this->routes) {
            return $this->caseInsensitivePrefixes;
        }

        return self::staticPrefixes(self::buildCollection(self::caseInsensitiveRoutes($this->routes)));
    }

    /**
     * The distinct set of class-level prefixes claimed via #[Route(exclusive: true)]. Multiple routes
     * belonging to the same class carry the identical prefix string, so it is deduplicated here rather
     * than recomputed per route — unlike static/case-insensitive prefixes, this one is never derived from
     * a route's own path (a method's composed path is always more specific than its class's own prefix).
     *
     * @internal dispatch plumbing, not part of the metadata surface — see docs/EXTENDING.md
     *
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool, classExclusivePrefix?: string|null}> $routes
     *
     * @return list<string>
     */
    public static function classExclusivePrefixes(array $routes): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                static fn (array $route): ?string => $route['classExclusivePrefix'] ?? null,
                $routes,
            ),
            static fn (?string $prefix): bool => null !== $prefix,
        )));
    }

    /**
     * @internal dispatch plumbing, not part of the metadata surface — see docs/EXTENDING.md
     *
     * @return list<string>
     */
    public function getClassExclusivePrefixes(): array
    {
        if ([] !== $this->classExclusivePrefixes || [] === $this->routes) {
            return $this->classExclusivePrefixes;
        }

        return self::classExclusivePrefixes($this->routes);
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
     * The route's own #[Cors] override, or null when it falls back to the global CORS configuration.
     *
     * @return array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}|null
     */
    public function getCorsConfig(string $routeName): ?array
    {
        return $this->corsConfigs[$routeName] ?? null;
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
     * #[Param] descriptions for this route, keyed by wire name. Empty when no parameter declared one.
     *
     * @return array<string, string>
     */
    public function getParamDescriptions(string $routeName): array
    {
        return $this->paramDescriptions[$routeName] ?? [];
    }

    /**
     * Input keys of this route whose requirement was contributed by a #[Param] on a defaulted
     * parameter: their absence falls back to that default instead of being a 400. A requirement
     * declared on the #[Route] itself is never listed here and stays mandatory.
     *
     * @return list<string>
     */
    public function getOptionalInputs(string $routeName): array
    {
        return $this->optionalInputs[$routeName] ?? [];
    }

    /**
     * @return array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool, tags?: list<string>}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * The prefixes the dispatcher turns into its path gate, so no configuration is needed to keep
     * matching off the hot path for ordinary page requests. Baked in at container build time; the
     * fallback mirrors getMatcher() and covers registries constructed without compiled data (tests,
     * manual wiring), which would otherwise end up behind a gate that lets nothing through.
     *
     * @internal dispatch plumbing, not part of the metadata surface — see docs/EXTENDING.md
     *
     * @return list<string>
     */
    public function getStaticPrefixes(): array
    {
        if ([] !== $this->staticPrefixes || [] === $this->routes) {
            return $this->staticPrefixes;
        }

        return self::staticPrefixes($this->getRouteCollection());
    }

    private function getCaseInsensitiveCollection(): RouteCollection
    {
        if (null !== $this->caseInsensitiveCollection) {
            return $this->caseInsensitiveCollection;
        }

        $collection = self::buildCollection(self::caseInsensitiveRoutes($this->routes));
        foreach ($collection->all() as $route) {
            $route->setOption('compiler_class', CaseInsensitiveRouteCompiler::class);
        }

        return $this->caseInsensitiveCollection = $collection;
    }
}
