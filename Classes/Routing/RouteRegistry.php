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
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function bin2hex;
use function preg_match;
use function preg_replace_callback;
use function rtrim;
use function strtoupper;

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
    private ?RouteCollection $legacyCollection = null;

    /**
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool, tags?: list<string>, classExclusivePrefix?: string|null, canonical?: bool, sites?: list<string>, languages?: list<int>, deprecation?: array{since: int, sunset: int|null, successor: string|null, documentation: string|null}, legacyPaths?: list<string>, legacyAlias?: bool}> $routes
     * @param array<string, array{lifetime: int, tags: list<string>, ignoreParams: list<string>}>                                                                                                                                                                                                                                                                                                                                                                                                                                     $cacheConfigs
     * @param array<string, array{limit: int, interval: string, policy: string, keyBy: string}>                                                                                                                                                                                                                                                                                                                                                                                                                                       $rateLimits
     * @param array<string, list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>>                                                                                                                                                                                                                                                                                                                                                                                           $arguments
     * @param array<string, list<array{service: string, options: array<string, mixed>}>>                                                                                                                                                                                                                                                                                                                                                                                                                                              $authenticators
     * @param array<string, string>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   $requestTokenScopes
     * @param array<mixed>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            $compiledRoutes
     * @param array<string, array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}>                                                                                                                                                                                                                                                                                                                                                                                  $corsConfigs
     * @param list<string>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            $staticPrefixes
     * @param array<string, array<string, string>>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    $paramDescriptions
     * @param array<string, list<string>>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             $optionalInputs
     * @param list<string>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            $caseInsensitivePrefixes
     * @param list<string>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            $classExclusivePrefixes
     * @param array<string, array{since: int, sunset: int|null, successor: string|null, documentation: string|null}>                                                                                                                                                                                                                                                                                                                                                                                                                  $deprecations
     * @param array<string, list<array{status: int, schema: class-string|null, collection: bool, description: string|null}>>                                                                                                                                                                                                                                                                                                                                                                                                          $returns
     * @param array<string, string>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   $aliases
     * @param list<string>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            $legacyPrefixes
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
        private readonly array $deprecations = [],
        private readonly array $returns = [],
        private readonly array $aliases = [],
        private readonly array $legacyPrefixes = [],
    ) {}

    /**
     * Builds the Symfony route collection from baked route arrays. Shared by the lazy runtime
     * collection (URL generation, matcher fallback) and the compiler pass, which dumps the same
     * collection into the compiled matcher format at container build time.
     *
     * @internal dispatch/URL-generation plumbing, not part of the metadata surface — see docs/background/extending.md
     *
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool, tags?: list<string>, classExclusivePrefix?: string|null, canonical?: bool, sites?: list<string>, languages?: list<int>}> $routes
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
                    '_sites' => $route['sites'] ?? [],
                    '_languages' => $route['languages'] ?? [],
                ],
                $route['requirements'],
                self::needsUtf8($route) ? ['utf8' => true] : [],
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
     * A non-ASCII prefix contributes its percent-encoded form too: `SiteBasePathResolver` hands the
     * gate the raw, percent-encoded request path (TYPO3's `Uri` never decodes it), while the compiled
     * static prefix is the literal UTF-8 the route declared. Without this, a non-ASCII route would
     * compile and match, yet never reach the matcher at all.
     *
     * @internal dispatch plumbing, not part of the metadata surface — see docs/background/extending.md
     *
     * @return list<string>
     */
    public static function staticPrefixes(RouteCollection $collection): array
    {
        $prefixes = [];
        foreach ($collection->all() as $route) {
            $prefix = $route->compile()->getStaticPrefix();
            foreach (self::withSlashlessVariant($prefix) as $variant) {
                $prefixes[] = $variant;
            }

            $encoded = self::percentEncodeNonAscii($prefix);
            if ($encoded !== $prefix) {
                foreach (self::withSlashlessVariant($encoded) as $variant) {
                    $prefixes[] = $variant;
                }
            }
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * Aliases live on the collection, not in the baked route array, so they resolve for URL generation
     * (a plain UrlGenerator built over this collection follows them transitively) while staying
     * invisible to getRoutes() — routing:debug and the OpenAPI export never see them as routes.
     *
     * @internal dispatch/URL-generation plumbing, not part of the metadata surface — see docs/background/extending.md
     */
    public function getRouteCollection(): RouteCollection
    {
        if (null !== $this->collection) {
            return $this->collection;
        }

        $collection = self::buildCollection($this->routes);
        foreach ($this->aliases as $alias => $target) {
            $collection->addAlias($alias, $target);
        }

        return $this->collection = $collection;
    }

    /**
     * Matching prefers the routes pre-compiled at container build time: the plain UrlMatcher would
     * re-compile every route's regex on each request. The fallback covers registries constructed
     * without compiled routes (tests, manual wiring).
     *
     * @internal dispatch plumbing, not part of the metadata surface — see docs/background/extending.md
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
     * @internal dispatch plumbing, not part of the metadata surface — see docs/background/extending.md
     *
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool, tags?: list<string>, classExclusivePrefix?: string|null, canonical?: bool, sites?: list<string>, languages?: list<int>}> $routes
     *
     * @return array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool, tags?: list<string>, classExclusivePrefix?: string|null, canonical?: bool, sites?: list<string>, languages?: list<int>}>
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
     * @internal dispatch plumbing, not part of the metadata surface — see docs/background/extending.md
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
     * @internal dispatch plumbing, not part of the metadata surface — see docs/background/extending.md
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
     * @internal dispatch plumbing, not part of the metadata surface — see docs/background/extending.md
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
     * @internal dispatch plumbing, not part of the metadata surface — see docs/background/extending.md
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
     * Synthetic route entries for every #[Route(legacyPaths:)] declaration, one per legacy path, keyed
     * by a name that can never collide with a real route name (`_`-prefixed, unlike every real name
     * derived by the compiler). Each entry is an exact copy of its owning route except for `path`, plus
     * an internal `_legacyOf` default naming that owner — `RouteMatcher` reads it (carried through into
     * the match array like `_controller`/`_env`) to rewrite `_route` back to the real name after a
     * match, so the dispatcher, rate limiting, caching, and #[DeprecatedRoute] headers all key off the
     * same route identity regardless of which path reached it. `sites`/`languages` are copied too, for
     * the same reason: a site/language-scoped route must stay out of scope through its legacy path as
     * well, not just its declared one.
     *
     * @internal dispatch plumbing, not part of the metadata surface — see docs/background/extending.md
     *
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool, canonical?: bool, sites?: list<string>, languages?: list<int>, legacyPaths?: list<string>, legacyAlias?: bool}> $routes
     *
     * @return array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, sites: list<string>, languages: list<int>}>
     */
    public static function legacyRoutes(array $routes): array
    {
        $legacy = [];
        foreach ($routes as $name => $route) {
            foreach ($route['legacyPaths'] ?? [] as $index => $legacyPath) {
                $legacy['_legacy_'.$name.'_'.$index] = [
                    'path' => $legacyPath,
                    'methods' => $route['methods'],
                    'controller' => $route['controller'],
                    'env' => $route['env'],
                    'requirements' => $route['requirements'],
                    'priority' => $route['priority'] ?? 0,
                    'defaults' => [...($route['defaults'] ?? []), '_legacyOf' => $name],
                    'schemes' => $route['schemes'] ?? [],
                    'host' => $route['host'] ?? null,
                    'sites' => $route['sites'] ?? [],
                    'languages' => $route['languages'] ?? [],
                ];
            }
        }

        return $legacy;
    }

    /**
     * A second matcher over every legacy path, or null when no route declared one — which is the
     * default, so the whole feature costs nothing until it is used. RouteMatcher consults it only after
     * the primary matcher (and, where applicable, the case-insensitive one) has already failed.
     *
     * @internal dispatch plumbing, not part of the metadata surface — see docs/background/extending.md
     */
    public function getLegacyMatcher(RequestContext $context): ?UrlMatcherInterface
    {
        $collection = $this->getLegacyCollection();

        return 0 === $collection->count() ? null : new UrlMatcher($collection, $context);
    }

    /**
     * The legacy paths' static prefixes, so the dispatcher's path gate lets them through in the first
     * place. Baked in at container build time; the fallback mirrors getCaseInsensitivePrefixes().
     *
     * @internal dispatch plumbing, not part of the metadata surface — see docs/background/extending.md
     *
     * @return list<string>
     */
    public function getLegacyPrefixes(): array
    {
        if ([] !== $this->legacyPrefixes || [] === $this->routes) {
            return $this->legacyPrefixes;
        }

        return self::staticPrefixes(self::buildCollection(self::legacyRoutes($this->routes)));
    }

    /**
     * Whether this route's legacy paths answer directly instead of redirecting to the declared path.
     * False by default (redirect).
     */
    public function isLegacyAlias(string $routeName): bool
    {
        return $this->routes[$routeName]['legacyAlias'] ?? false;
    }

    /**
     * @internal dispatch plumbing, not part of the metadata surface — see docs/background/extending.md
     */
    public function getControllerLocator(): ContainerInterface
    {
        return $this->controllerLocator;
    }

    /**
     * @internal dispatch plumbing, not part of the metadata surface — see docs/background/extending.md
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
     * Whether a request that only matched a tolerated variant of this route's path should be
     * redirected to the declared form instead of served directly. False by default.
     */
    public function isCanonical(string $routeName): bool
    {
        return $this->routes[$routeName]['canonical'] ?? false;
    }

    /**
     * The route's declared #[Returns] response schemas. An empty list means none were declared, and the
     * OpenAPI export falls back to its generic 200 description.
     *
     * @return list<array{status: int, schema: class-string|null, collection: bool, description: string|null}>
     */
    public function getReturns(string $routeName): array
    {
        return $this->returns[$routeName] ?? [];
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
     * @return array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool, tags?: list<string>, classExclusivePrefix?: string|null, canonical?: bool, sites?: list<string>, languages?: list<int>, legacyPaths?: list<string>, legacyAlias?: bool}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * The route's #[DeprecatedRoute] state, or null when it was not declared. `since`/`sunset` are
     * Unix timestamps, already validated at build time.
     *
     * @return array{since: int, sunset: int|null, successor: string|null, documentation: string|null}|null
     */
    public function getDeprecation(string $routeName): ?array
    {
        return $this->deprecations[$routeName] ?? null;
    }

    /**
     * Whether this route's compiled regex carries PCRE's `u` modifier — the same decision
     * {@see needsUtf8()} made once at collection-build time. `RouteMatcher` consults this when
     * re-validating a requirement after a case-insensitive match, so the revalidation regex agrees with
     * the Unicode mode the route was actually compiled with.
     *
     * @internal dispatch plumbing, not part of the metadata surface — see docs/background/extending.md
     */
    public function routeNeedsUtf8(string $routeName): bool
    {
        return isset($this->routes[$routeName]) && self::needsUtf8($this->routes[$routeName]);
    }

    /**
     * The alias names that resolve to this route, in `routing:debug` for the route they belong to.
     * Empty when the route declared none.
     *
     * @return list<string>
     */
    public function getAliasesFor(string $routeName): array
    {
        return array_keys(array_filter($this->aliases, static fn (string $target): bool => $target === $routeName));
    }

    /**
     * The prefixes the dispatcher turns into its path gate, so no configuration is needed to keep
     * matching off the hot path for ordinary page requests. Baked in at container build time; the
     * fallback mirrors getMatcher() and covers registries constructed without compiled data (tests,
     * manual wiring), which would otherwise end up behind a gate that lets nothing through.
     *
     * @internal dispatch plumbing, not part of the metadata surface — see docs/background/extending.md
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

    /**
     * Symfony's route compiler refuses a non-ASCII path or requirement pattern outright unless the
     * "utf8" option is set (it adds the `u` regex modifier). Derived rather than demanded: this
     * extension has no legacy YAML/XML routes to stay compatible with, the reason Symfony itself
     * defaults it off. Never set unconditionally — the `u` modifier makes a request path containing
     * invalid UTF-8 fail the regex instead of simply not matching.
     *
     * @param array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool} $route
     */
    private static function needsUtf8(array $route): bool
    {
        if (self::containsNonAscii($route['path'])) {
            return true;
        }

        if (self::containsNonAscii($route['host'] ?? '')) {
            return true;
        }

        foreach ($route['requirements'] as $pattern) {
            if (self::containsNonAscii($pattern) || self::hasUnicodeRegexConstruct($pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function containsNonAscii(string $value): bool
    {
        return 1 === preg_match('/[\x80-\xFF]/', $value);
    }

    /**
     * `\p{...}`/`\P{...}` (and their brace-less single-letter shorthand `\pL`/`\PL`), `\X` and `\x{...}`
     * all require PCRE's Unicode mode even in an otherwise all-ASCII pattern.
     */
    private static function hasUnicodeRegexConstruct(string $pattern): bool
    {
        return 1 === preg_match('/\\\\[pP](?:\{|[A-Za-z])|\\\\X|\\\\x\{/', $pattern);
    }

    /**
     * @return list<string>
     */
    private static function withSlashlessVariant(string $prefix): array
    {
        $variants = [$prefix];

        $slashless = rtrim($prefix, '/');
        if ('' !== $slashless) {
            $variants[] = $slashless;
        }

        return $variants;
    }

    /**
     * Percent-encodes only the non-ASCII bytes of a path, leaving `/` and every ASCII character
     * untouched — matching how a PSR-7 `Uri` encodes a UTF-8 path (`rawurlencode()` would also encode
     * the segment separators, which a prefix cannot afford to lose).
     */
    private static function percentEncodeNonAscii(string $value): string
    {
        return preg_replace_callback(
            '/[\x80-\xFF]/',
            static fn (array $match): string => '%'.strtoupper(bin2hex($match[0])),
            $value,
        ) ?? $value;
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

    private function getLegacyCollection(): RouteCollection
    {
        return $this->legacyCollection ??= self::buildCollection(self::legacyRoutes($this->routes));
    }
}
