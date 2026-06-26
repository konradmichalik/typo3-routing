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

use KonradMichalik\Typo3Routing\Attribute\{Cache, Route};
use KonradMichalik\Typo3Routing\Routing\{RouteControllerInterface, RouteRegistry};
use LogicException;
use Override;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\{CompilerPassInterface, ServiceLocatorTagPass};
use Symfony\Component\DependencyInjection\{ContainerBuilder, Definition, Reference};

use function is_string;
use function sprintf;

/**
 * RouteCompilerPass.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RouteCompilerPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(RouteRegistry::class)) {
            return;
        }

        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [];
        /** @var array<string, array{lifetime: int, tags: list<string>, ignoreParams: list<string>}> $cacheConfigs */
        $cacheConfigs = [];
        /** @var array<string, Reference> $controllerReferences */
        $controllerReferences = [];

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $this->resolveControllerClass($container, $definition);
            if (null === $class) {
                continue;
            }

            if ($this->collectRoutes(new ReflectionClass($class), $serviceId, $routes, $cacheConfigs)) {
                // Keep the controller fetchable from the locator even though it stays a private service.
                $controllerReferences[$serviceId] = new Reference($serviceId);
            }
        }

        $locator = ServiceLocatorTagPass::register($container, $controllerReferences);

        $registry = $container->getDefinition(RouteRegistry::class);
        $registry->setArgument('$routes', $routes);
        $registry->setArgument('$controllerLocator', $locator);
        $registry->setArgument('$cacheConfigs', $cacheConfigs);
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
     * @param ReflectionClass<object>                                                                                                              $reflection
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes
     * @param array<string, array{lifetime: int, tags: list<string>, ignoreParams: list<string>}>                                                  $cacheConfigs
     */
    private function collectRoutes(ReflectionClass $reflection, string $serviceId, array &$routes, array &$cacheConfigs): bool
    {
        $found = false;
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }

            $cache = $this->resolveCache($method);

            foreach ($method->getAttributes(Route::class) as $attribute) {
                $route = $attribute->newInstance();
                $name = $route->name ?? $this->deriveRouteName($serviceId, $method->getName());

                if (isset($routes[$name])) {
                    throw new LogicException(sprintf('Duplicate attribute route name "%s": already defined by "%s", redefined by "%s::%s()". Set an explicit "name" on the #[Route] attribute to disambiguate.', $name, $routes[$name]['controller'], $serviceId, $method->getName()), 1750000000);
                }

                $routes[$name] = [
                    'path' => $route->path,
                    'methods' => array_map(strtoupper(...), $route->methods),
                    'controller' => $serviceId.'::'.$method->getName(),
                    'env' => $route->env,
                    'requirements' => $route->requirements,
                ];
                if (null !== $cache) {
                    $cacheConfigs[$name] = $cache;
                }
                $found = true;
            }
        }

        return $found;
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
