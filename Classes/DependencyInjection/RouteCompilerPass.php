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

use KonradMichalik\Typo3Routing\Attribute\{Authenticate, Cache, RateLimit, RequireRequestToken, Route};
use KonradMichalik\Typo3Routing\Authentication\RouteAuthenticatorInterface;
use KonradMichalik\Typo3Routing\Routing\{RouteControllerInterface, RouteRegistry};
use LogicException;
use Override;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\{CompilerPassInterface, ServiceLocatorTagPass};
use Symfony\Component\DependencyInjection\{ContainerBuilder, Definition, Reference};

use function array_intersect;
use function array_map;
use function class_exists;
use function count;
use function in_array;
use function is_a;
use function is_string;
use function sprintf;
use function trigger_error;

use const E_USER_WARNING;

/**
 * RouteCompilerPass.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RouteCompilerPass implements CompilerPassInterface
{
    /**
     * @var list<string>
     */
    private const SUPPORTED_RATE_LIMIT_POLICIES = ['sliding_window', 'fixed_window'];

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
    ];

    public function __construct(
        private ArgumentSpecFactory $argumentSpecs = new ArgumentSpecFactory(),
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

            if ($this->collectController(new ReflectionClass($class), $serviceId, $container, $collected)) {
                // Keep the controller fetchable from the locator even though it stays a private service.
                $controllerReferences[$serviceId] = new Reference($serviceId);
            }
        }

        $registry = $container->getDefinition(RouteRegistry::class);
        $registry->setArgument('$routes', $collected->routes);
        $registry->setArgument('$controllerLocator', ServiceLocatorTagPass::register($container, $controllerReferences));
        $registry->setArgument('$authenticatorLocator', ServiceLocatorTagPass::register($container, $collected->authenticatorReferences));
        $registry->setArgument('$cacheConfigs', $collected->cacheConfigs);
        $registry->setArgument('$rateLimits', $collected->rateLimits);
        $registry->setArgument('$arguments', $collected->arguments);
        $registry->setArgument('$authenticators', $collected->authenticators);
        $registry->setArgument('$requestTokenScopes', $collected->requestTokenScopes);
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
        if (!is_string($resolvedClass) || !class_exists($resolvedClass)) {
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
     */
    private function collectController(ReflectionClass $reflection, string $serviceId, ContainerBuilder $container, CollectedRoutes $collected): bool
    {
        $classRoute = $this->resolveClassRoute($reflection, $serviceId);

        $found = false;
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }

            $found = $this->collectMethod($method, $serviceId, $container, $collected, $classRoute) || $found;
        }

        return $found;
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

    private function collectMethod(ReflectionMethod $method, string $serviceId, ContainerBuilder $container, CollectedRoutes $collected, ?Route $classRoute): bool
    {
        $routeAttributes = $method->getAttributes(Route::class);
        if ([] === $routeAttributes) {
            $this->assertNoOrphanedModifiers($method, $serviceId);

            return false;
        }

        $cache = $this->resolveCache($method);
        $rateLimit = $this->resolveRateLimit($method, $serviceId);
        $auth = $this->resolveAuthenticators($method, $serviceId, $container, $collected);
        $requestToken = $this->resolveRequestToken($method);

        foreach ($routeAttributes as $attribute) {
            $this->storeRoute($attribute->newInstance(), $method, $serviceId, $cache, $rateLimit, $auth, $requestToken, $collected, $classRoute);
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
     * @param array{limit: int, interval: string, policy: string}|null                  $rateLimit
     * @param list<array{service: string, options: array<string, mixed>}>               $auth
     */
    private function storeRoute(Route $route, ReflectionMethod $method, string $serviceId, ?array $cache, ?array $rateLimit, array $auth, ?RequireRequestToken $requestToken, CollectedRoutes $collected, ?Route $classRoute): void
    {
        // Class-level #[Route] prefixes the path/name, defaults the env and provides base requirements.
        $namePrefix = '';
        $pathPrefix = '';
        $classRequirements = [];
        if ($classRoute instanceof Route) {
            $namePrefix = $classRoute->name ?? '';
            $pathPrefix = $classRoute->path;
            $classRequirements = $classRoute->requirements;
        }

        $name = $namePrefix.($route->name ?? $this->deriveRouteName($serviceId, $method->getName()));

        if (isset($collected->routes[$name])) {
            throw new LogicException(sprintf('Duplicate attribute route name "%s": already defined by "%s", redefined by "%s::%s()". Set an explicit "name" on the #[Route] attribute to disambiguate.', $name, $collected->routes[$name]['controller'], $serviceId, $method->getName()), 1750000000);
        }

        // The method wins per requirement key; a method env overrides the class default.
        $path = $pathPrefix.$route->path;
        $requirements = [...$classRequirements, ...$route->requirements];

        $methods = array_map(strtoupper(...), $route->methods);
        $collected->routes[$name] = [
            'path' => $path,
            'methods' => $methods,
            'controller' => $serviceId.'::'.$method->getName(),
            'env' => $route->env ?? $classRoute?->env,
            'requirements' => $requirements,
        ];
        $collected->arguments[$name] = $this->argumentSpecs->build($method, $path, $serviceId);

        if (null !== $rateLimit) {
            $collected->rateLimits[$name] = $rateLimit;
        }
        if ([] !== $auth) {
            $collected->authenticators[$name] = $auth;
        }

        $this->applyCache($cache, $auth, $name, $serviceId, $method, $collected);
        $this->applyRequestToken($requestToken, $methods, $name, $serviceId, $method, $collected);
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

        if ([] === array_intersect(['POST', 'PUT', 'PATCH'], $methods)) {
            throw new LogicException(sprintf('#[RequireRequestToken] on "%s::%s()" (route "%s") is pointless: the route only allows "%s". Request tokens are verified for POST/PUT/PATCH only.', $serviceId, $method->getName(), $name, implode('", "', $methods)), 1750000012);
        }

        $collected->requestTokenScopes[$name] = $requestToken->scope ?? 'routing/'.$name;
    }

    /**
     * Resolves the route's #[Authenticate] attributes (OR-combined) and registers each referenced
     * authenticator class in the locator. Fails the build on an unknown class, a class that does not
     * implement the contract, or one that is not a registered service.
     *
     * @return list<array{service: string, options: array<string, mixed>}>
     */
    private function resolveAuthenticators(ReflectionMethod $method, string $serviceId, ContainerBuilder $container, CollectedRoutes $collected): array
    {
        $result = [];
        foreach ($method->getAttributes(Authenticate::class) as $attribute) {
            $authenticate = $attribute->newInstance();
            $class = $authenticate->authenticator;

            if (!class_exists($class) || !is_a($class, RouteAuthenticatorInterface::class, true)) {
                throw new LogicException(sprintf('#[Authenticate] on "%s::%s()" references "%s", which does not implement %s.', $serviceId, $method->getName(), $class, RouteAuthenticatorInterface::class), 1750000010);
            }

            if (!$container->hasDefinition($class) && !$container->hasAlias($class)) {
                throw new LogicException(sprintf('#[Authenticate] authenticator "%s" on "%s::%s()" is not a registered service. Register it (autoconfiguration in Services.yaml is enough).', $class, $serviceId, $method->getName()), 1750000011);
            }

            $collected->authenticatorReferences[$class] ??= new Reference($class);
            $result[] = ['service' => $class, 'options' => $authenticate->options];
        }

        return $result;
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

    /**
     * @return array{limit: int, interval: string, policy: string}|null
     */
    private function resolveRateLimit(ReflectionMethod $method, string $serviceId): ?array
    {
        $attributes = $method->getAttributes(RateLimit::class);
        if ([] === $attributes) {
            return null;
        }

        $rateLimit = $attributes[0]->newInstance();

        if (!in_array($rateLimit->policy, self::SUPPORTED_RATE_LIMIT_POLICIES, true)) {
            throw new LogicException(sprintf('Unsupported #[RateLimit] policy "%s" on "%s::%s()". Supported policies are "%s".', $rateLimit->policy, $serviceId, $method->getName(), implode('", "', self::SUPPORTED_RATE_LIMIT_POLICIES)), 1750000001);
        }

        return ['limit' => $rateLimit->limit, 'interval' => $rateLimit->interval, 'policy' => $rateLimit->policy];
    }

    private function deriveRouteName(string $serviceId, string $method): string
    {
        return strtolower(str_replace('\\', '_', $serviceId).'_'.$method);
    }
}
