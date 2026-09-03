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

namespace KonradMichalik\Typo3Routing\DependencyInjection;

use KonradMichalik\Typo3Routing\Attribute\{Authenticate, Cache, Cors, DeprecatedRoute, RateLimit, RequireRequestToken, Returns, Route};
use KonradMichalik\Typo3Routing\Routing\{RouteControllerInterface, RouteRegistry};
use LogicException;
use Override;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\{CompilerPassInterface, ServiceLocatorTagPass};
use Symfony\Component\DependencyInjection\{ContainerBuilder, Definition, Reference};
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;

use function array_intersect;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function is_a;
use function is_string;
use function sprintf;
use function str_replace;
use function trigger_error;

use const E_USER_WARNING;

/**
 * RouteCompilerPass.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RouteCompilerPass implements CompilerPassInterface
{
    /**
     * Attributes that only take effect alongside a #[Route] on the same method.
     *
     * @var array<class-string, string>
     */
    private const MODIFIER_ATTRIBUTES = [
        Cache::class => '#[Cache]',
        RateLimit::class => '#[RateLimit]',
        Authenticate::class => '#[Authenticate]',
        RequireRequestToken::class => '#[RequireRequestToken]',
        Cors::class => '#[Cors]',
        DeprecatedRoute::class => '#[DeprecatedRoute]',
        Returns::class => '#[Returns]',
    ];

    public function __construct(
        private ArgumentSpecFactory $argumentSpecs = new ArgumentSpecFactory(),
        private CorsResolver $corsResolver = new CorsResolver(),
        private AuthenticateResolver $authenticateResolver = new AuthenticateResolver(),
        private ClassExistenceChecker $classExistenceChecker = new ClassExistenceChecker(),
        private CompilerWarnings $compilerWarnings = new CompilerWarnings(),
        private EmptyPathGuard $emptyPathGuard = new EmptyPathGuard(),
        private PlaceholderSyntaxGuard $placeholderSyntaxGuard = new PlaceholderSyntaxGuard(),
        private ClassExclusiveResolver $classExclusiveResolver = new ClassExclusiveResolver(),
        private DeprecationResolver $deprecationResolver = new DeprecationResolver(),
        private RateLimitResolver $rateLimitResolver = new RateLimitResolver(),
        private ReturnsResolver $returnsResolver = new ReturnsResolver(),
        private RouteCompileGuard $compileGuard = new RouteCompileGuard(),
        private RouteAliasCollector $aliasCollector = new RouteAliasCollector(),
        private LegacyPathValidator $legacyPathValidator = new LegacyPathValidator(),
        private JsonErrorResolver $jsonErrorResolver = new JsonErrorResolver(),
    ) {}

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(RouteRegistry::class)) {
            return;
        }

        $collected = new CollectedRoutes();
        /** @var array<string, Reference> $controllerReferences */
        $controllerReferences = [];

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $this->resolveControllerClass($container, $definition);
            if (null === $class) {
                continue;
            }

            ['hasRoute' => $hasRoute, 'hasExclusiveClaim' => $hasExclusiveClaim] = $this->collectController(new ReflectionClass($class), $serviceId, $container, $collected);
            // A class-level exclusive claim with no method route is a deliberate pattern (see
            // RoutelessExclusiveController), not the "forgotten #[Route]" mistake the warning targets.
            $this->compilerWarnings->warnIfControllerHasNoRoute($hasRoute || $hasExclusiveClaim, $serviceId, RouteControllerInterface::class);
            if ($hasRoute) {
                // Keep the controller fetchable from the locator even though it stays a private service.
                $controllerReferences[$serviceId] = new Reference($serviceId);
            }
        }

        $this->deprecationResolver->assertSuccessorsExist($collected);
        $this->aliasCollector->assertNoCollisionWithRoutes($collected);
        $this->legacyPathValidator->assertNoCollisions($collected);

        $registry = $container->getDefinition(RouteRegistry::class);
        $registry->setArgument('$routes', $collected->routes);
        $registry->setArgument('$deprecations', $collected->deprecations);
        $registry->setArgument('$aliases', $collected->aliases);
        $registry->setArgument('$controllerLocator', ServiceLocatorTagPass::register($container, $controllerReferences));
        $registry->setArgument('$authenticatorLocator', ServiceLocatorTagPass::register($container, $collected->authenticatorReferences));
        $registry->setArgument('$cacheConfigs', $collected->cacheConfigs);
        $registry->setArgument('$rateLimits', $collected->rateLimits);
        $registry->setArgument('$arguments', $collected->arguments);
        $registry->setArgument('$authenticators', $collected->authenticators);
        $registry->setArgument('$requestTokenScopes', $collected->requestTokenScopes);
        $registry->setArgument('$corsConfigs', $collected->corsConfigs);
        $registry->setArgument('$returns', $collected->returns);
        $registry->setArgument('$jsonErrorRoutes', $collected->jsonErrorRoutes);
        // Routes contributing nothing are dropped rather than baked as empty arrays.
        $registry->setArgument('$paramDescriptions', array_filter($collected->paramDescriptions));
        $registry->setArgument('$optionalInputs', array_filter($collected->optionalInputs));

        $collection = RouteRegistry::buildCollection($collected->routes);
        $this->compileGuard->assertCompiles($collection, $collected->routes);
        // Pre-compile the matcher tables so request-time matching never re-compiles route regexes.
        $registry->setArgument('$compiledRoutes', (new CompiledUrlMatcherDumper($collection))->getCompiledRoutes());
        // The dispatcher's path gate is derived from the routes themselves, so it needs no configuration.
        // A non-ASCII prefix's percent-encoded form (see RouteRegistry::staticPrefixes()) contains a
        // literal "%", which the container's parameter bag would otherwise try to resolve as "%C3%" —
        // escaped here the same way any literal "%" in a baked argument has to be.
        $escapePercent = static fn (string $prefix): string => str_replace('%', '%%', $prefix);
        $registry->setArgument('$staticPrefixes', array_map($escapePercent, RouteRegistry::staticPrefixes($collection)));
        // Compiled separately: the gate has to open for these in every casing, and the case-insensitive
        // compilation itself carries no usable prefix.
        $registry->setArgument('$caseInsensitivePrefixes', array_map($escapePercent, RouteRegistry::staticPrefixes(RouteRegistry::buildCollection(RouteRegistry::caseInsensitiveRoutes($collected->routes)))));
        // A class's own exclusive claim, one entry per opted-in class regardless of how many routes it
        // declares — recorded independently in $collected->classExclusivePrefixes (not derived from
        // $collected->routes), so a class with no method routes yet still keeps its claim.
        $registry->setArgument('$classExclusivePrefixes', array_values(array_unique($collected->classExclusivePrefixes)));
        // A legacy path lives in its own collection (see RouteRegistry::legacyRoutes()), consulted only
        // after the primary matcher already failed — so a route with no legacy paths pays nothing.
        $registry->setArgument('$legacyPrefixes', array_map($escapePercent, RouteRegistry::staticPrefixes(RouteRegistry::buildCollection(RouteRegistry::legacyRoutes($collected->routes)))));
    }

    /**
     * @return class-string|null the controller class implementing the marker interface, or null to skip
     */
    private function resolveControllerClass(ContainerBuilder $container, Definition $definition): ?string
    {
        if ($definition->isAbstract()) {
            return null;
        }

        $class = $definition->getClass();
        if (!is_string($class) || '' === $class) {
            return null;
        }

        $resolvedClass = $container->getParameterBag()->resolveValue($class);
        if (!is_string($resolvedClass) || !$this->classExistenceChecker->exists($resolvedClass)) {
            return null;
        }

        if (!is_a($resolvedClass, RouteControllerInterface::class, true)) {
            return null;
        }

        if ((new ReflectionClass($resolvedClass))->isAbstract()) {
            return null;
        }

        return $resolvedClass;
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return array{hasRoute: bool, hasExclusiveClaim: bool}
     */
    private function collectController(ReflectionClass $reflection, string $serviceId, ContainerBuilder $container, CollectedRoutes $collected): array
    {
        $classRoute = $this->resolveClassRoute($reflection, $serviceId);
        $classCors = $this->corsResolver->resolveClass($reflection);
        $classExclusivePrefix = $this->classExclusiveResolver->resolvePrefix($classRoute, $serviceId);
        $collected->recordClassExclusivePrefix($classExclusivePrefix);
        $classDeprecation = $this->deprecationResolver->resolveClass($reflection);
        $classRateLimit = $this->rateLimitResolver->resolveClass($reflection);
        $classAuth = $this->authenticateResolver->resolveClass($reflection);

        $found = false;
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }

            $found = $this->collectMethod($method, $serviceId, $container, $collected, $classRoute, $classCors, $classExclusivePrefix, $classDeprecation, $classRateLimit, $classAuth) || $found;
        }

        return ['hasRoute' => $found, 'hasExclusiveClaim' => null !== $classExclusivePrefix];
    }

    /**
     * Reads the optional class-level #[Route] that prefixes every method route. At most one is allowed.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function resolveClassRoute(ReflectionClass $reflection, string $serviceId): ?Route
    {
        $attributes = $reflection->getAttributes(Route::class);
        if ([] === $attributes) {
            return null;
        }

        if (count($attributes) > 1) {
            throw new LogicException(sprintf('Multiple class-level #[Route] attributes on "%s" are not supported; a controller may declare at most one route prefix.', $serviceId), 1750000014);
        }

        return $attributes[0]->newInstance();
    }

    /**
     * @param list<Authenticate> $classAuth
     */
    private function collectMethod(ReflectionMethod $method, string $serviceId, ContainerBuilder $container, CollectedRoutes $collected, ?Route $classRoute, ?Cors $classCors, ?string $classExclusivePrefix, ?DeprecatedRoute $classDeprecation, ?RateLimit $classRateLimit, array $classAuth): bool
    {
        $overriddenRouteMethod = $this->compilerWarnings->findOverriddenRouteMethod($method, Route::class);
        $this->compilerWarnings->warnIfRouteWasDropped($overriddenRouteMethod, $method, $serviceId);

        $routeAttributes = $method->getAttributes(Route::class);
        if ([] === $routeAttributes) {
            $this->assertNoOrphanedModifiers($method, $serviceId);

            return false;
        }

        $cache = $this->resolveCache($method);
        $rateLimit = $this->rateLimitResolver->resolveMethod($method, $classRateLimit);
        $auth = $this->authenticateResolver->resolveMethod($method, $serviceId, $container, $collected, $classAuth);
        $requestToken = $this->resolveRequestToken($method);
        $cors = $this->corsResolver->resolveMethod($method, $classCors);
        $this->compilerWarnings->warnIfAModifierWasDropped($overriddenRouteMethod, $method, $serviceId, self::MODIFIER_ATTRIBUTES);
        $deprecation = $this->deprecationResolver->resolveMethod($method, $classDeprecation);
        $jsonErrors = $this->jsonErrorResolver->resolvesToJsonResponse($method);

        foreach ($routeAttributes as $attribute) {
            $this->storeRoute($attribute->newInstance(), $method, $serviceId, $cache, $rateLimit, $auth, $requestToken, $cors, $deprecation, $jsonErrors, $collected, $classRoute, $classExclusivePrefix);
        }

        return true;
    }

    /**
     * Fails the build when a route-modifier attribute sits on a method without a #[Route]: such
     * attributes would be silently dropped, and a missing #[Authenticate] is a security trap.
     */
    private function assertNoOrphanedModifiers(ReflectionMethod $method, string $serviceId): void
    {
        $orphaned = [];
        foreach (self::MODIFIER_ATTRIBUTES as $class => $label) {
            if ([] !== $method->getAttributes($class)) {
                $orphaned[] = $label;
            }
        }

        if ([] === $orphaned) {
            return;
        }

        throw new LogicException(sprintf('%s on "%s::%s()" cannot take effect without a #[Route] attribute on the same method. Add a #[Route] or remove the listed attribute(s).', implode(', ', $orphaned), $serviceId, $method->getName()), 1750000013);
    }

    /**
     * @param array{lifetime: int, tags: list<string>, ignoreParams: list<string>}|null $cache
     * @param list<array{service: string, options: array<string, mixed>}>               $auth
     */
    private function storeRoute(Route $route, ReflectionMethod $method, string $serviceId, ?array $cache, ?RateLimit $rateLimit, array $auth, ?RequireRequestToken $requestToken, ?Cors $cors, ?DeprecatedRoute $deprecation, bool $jsonErrors, CollectedRoutes $collected, ?Route $classRoute, ?string $classExclusivePrefix): void
    {
        $this->classExclusiveResolver->assertNotOnMethod($route, $method, $serviceId);

        // Class-level #[Route] prefixes the path/name, defaults the env and provides base requirements.
        $namePrefix = '';
        $pathPrefix = '';
        $classRequirements = [];
        $classDefaults = [];
        if ($classRoute instanceof Route) {
            $namePrefix = $classRoute->name ?? '';
            $pathPrefix = $classRoute->path;
            $classRequirements = $classRoute->requirements;
            $classDefaults = $classRoute->defaults;
        }

        $name = $namePrefix.($route->name ?? $this->deriveRouteName($serviceId, $method->getName()));

        if (isset($collected->routes[$name])) {
            throw new LogicException(sprintf('Duplicate attribute route name "%s": already defined by "%s", redefined by "%s::%s()". Set an explicit "name" on the #[Route] attribute to disambiguate.', $name, $collected->routes[$name]['controller'], $serviceId, $method->getName()), 1750000000);
        }

        // The method wins per requirement/default key; a method env overrides the class default.
        $path = $pathPrefix.$route->path;
        $this->emptyPathGuard->assertNotEmpty($path, $name, $serviceId, $method);
        $this->placeholderSyntaxGuard->assertSupported($path, $name, $serviceId, $method);
        $requirements = [...$classRequirements, ...$route->requirements];
        $defaults = [...$classDefaults, ...$route->defaults];

        // #[Param] contributions are collected before the route is stored, so they land in the same
        // requirements/defaults arrays that the matcher, routing:debug and the OpenAPI export read.
        $arguments = $this->argumentSpecs->build($method, $path, $serviceId);
        $contributions = $this->argumentSpecs->paramContributions($method, $arguments, $path, $route->requirements, $route->defaults, $serviceId);
        $requirements = [...$requirements, ...$contributions['requirements']];
        $defaults = [...$defaults, ...$contributions['defaults']];
        $collected->paramDescriptions[$name] = $contributions['descriptions'];
        $collected->optionalInputs[$name] = $contributions['optional'];

        $this->assertNoReservedDefaultKeys($defaults, $serviceId, $method, $name);

        $methods = array_map(strtoupper(...), $route->methods);
        // Null on both levels means "not set", which is the case-sensitive default.
        $caseInsensitive = $route->caseInsensitive ?? $classRoute?->caseInsensitive;
        $tags = $route->tags ?? $classRoute?->tags;
        // Null on both levels means "not set", which is "answer directly, no redirect".
        $canonical = $route->canonical ?? $classRoute?->canonical;
        $sites = $route->sites ?? $classRoute?->sites;
        $languages = $route->languages ?? $classRoute?->languages;
        $collected->routes[$name] = [
            'path' => $path,
            'methods' => $methods,
            'controller' => $serviceId.'::'.$method->getName(),
            'env' => $route->env ?? $classRoute?->env,
            'requirements' => $requirements,
            'priority' => $route->priority,
            'defaults' => $defaults,
            'schemes' => $route->schemes,
            'host' => $route->host,
            'description' => $route->description ?? $classRoute?->description,
            'caseInsensitive' => $caseInsensitive ?? false,
            'tags' => $tags ?? [],
            'classExclusivePrefix' => $classExclusivePrefix,
            'canonical' => $canonical ?? false,
            'sites' => $sites ?? [],
            'languages' => $languages ?? [],
            'legacyPaths' => $route->legacyPaths,
            'legacyAlias' => $route->legacyAlias,
        ];
        $collected->arguments[$name] = $arguments;
        $this->aliasCollector->apply($route->aliases, $namePrefix, $name, $serviceId, $method->getName(), $collected);

        if ([] !== $auth) {
            $collected->authenticators[$name] = $auth;
        }
        $this->jsonErrorResolver->apply($jsonErrors, $name, $collected);

        $this->applyCache($cache, $auth, $name, $serviceId, $method, $collected);
        $this->applyRequestToken($requestToken, $methods, $name, $serviceId, $method, $collected);
        $this->corsResolver->apply($cors, $name, $serviceId, $method->getName(), $collected);
        $this->deprecationResolver->apply($deprecation, $name, $serviceId, $method->getName(), $collected);
        $this->rateLimitResolver->apply($rateLimit, $name, $serviceId, $method->getName(), $collected);
        $this->returnsResolver->apply($method, $serviceId, $name, $collected);
    }

    /**
     * Rejects defaults whose key starts with "_": those collide with the internal metadata
     * (_controller/_env/_requirements, and Symfony's own _route) carried in the compiled route.
     *
     * @param array<string, mixed> $defaults
     */
    private function assertNoReservedDefaultKeys(array $defaults, string $serviceId, ReflectionMethod $method, string $name): void
    {
        $reserved = array_filter(array_keys($defaults), static fn (string $key): bool => str_starts_with($key, '_'));
        if ([] === $reserved) {
            return;
        }

        throw new LogicException(sprintf('#[Route] default(s) "%s" on "%s::%s()" (route "%s") use a reserved key: default names starting with "_" are used internally. Rename them.', implode('", "', $reserved), $serviceId, $method->getName(), $name), 1750000022);
    }

    /**
     * @param array{lifetime: int, tags: list<string>, ignoreParams: list<string>}|null $cache
     * @param list<array{service: string, options: array<string, mixed>}>               $auth
     */
    private function applyCache(?array $cache, array $auth, string $name, string $serviceId, ReflectionMethod $method, CollectedRoutes $collected): void
    {
        if (null === $cache) {
            return;
        }

        $collected->cacheConfigs[$name] = $cache;

        if ([] !== $auth) {
            // The response cache is force-disabled for authenticated routes (see RouteDispatcher),
            // because its key does not vary by identity and would leak data across clients.
            trigger_error(sprintf('Route "%s" (%s::%s()) combines #[Cache] with #[Authenticate]; the response cache is disabled (no-store) for authenticated routes to avoid leaking identity-specific data. Remove #[Cache] to silence this warning.', $name, $serviceId, $method->getName()), E_USER_WARNING);
        }
    }

    /**
     * @param list<string> $methods
     */
    private function applyRequestToken(?RequireRequestToken $requestToken, array $methods, string $name, string $serviceId, ReflectionMethod $method, CollectedRoutes $collected): void
    {
        if (null === $requestToken) {
            return;
        }

        if ([] === array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $methods)) {
            throw new LogicException(sprintf('#[RequireRequestToken] on "%s::%s()" (route "%s") is pointless: the route only allows "%s". Request tokens are verified for POST/PUT/PATCH/DELETE only.', $serviceId, $method->getName(), $name, implode('", "', $methods)), 1750000012);
        }

        $collected->requestTokenScopes[$name] = $requestToken->scope ?? 'routing/'.$name;
    }

    private function resolveRequestToken(ReflectionMethod $method): ?RequireRequestToken
    {
        $attributes = $method->getAttributes(RequireRequestToken::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }

    /**
     * @return array{lifetime: int, tags: list<string>, ignoreParams: list<string>}|null
     */
    private function resolveCache(ReflectionMethod $method): ?array
    {
        $attributes = $method->getAttributes(Cache::class);
        if ([] === $attributes) {
            return null;
        }

        $cache = $attributes[0]->newInstance();

        return ['lifetime' => $cache->lifetime, 'tags' => $cache->tags, 'ignoreParams' => $cache->ignoreParams];
    }

    private function deriveRouteName(string $serviceId, string $method): string
    {
        return strtolower(str_replace('\\', '_', $serviceId).'_'.$method);
    }
}
