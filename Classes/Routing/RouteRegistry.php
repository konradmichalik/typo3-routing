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

use Psr\Container\ContainerInterface;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\{RequestContext, Route as SymfonyRoute, RouteCollection};

/**
 * RouteRegistry.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RouteRegistry
{
    private ?RouteCollection $collection = null;

    /**
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes
     * @param array<string, array{lifetime: int, tags: list<string>, ignoreParams: list<string>}>                                                  $cacheConfigs
     * @param array<string, array{limit: int, interval: string, policy: string}>                                                                   $rateLimits
     * @param array<string, list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>>        $arguments
     */
    public function __construct(
        private readonly array $routes,
        private readonly ContainerInterface $controllerLocator,
        private readonly array $cacheConfigs = [],
        private readonly array $rateLimits = [],
        private readonly array $arguments = [],
    ) {}

    public function getRouteCollection(): RouteCollection
    {
        if (!$this->collection instanceof RouteCollection) {
            $collection = new RouteCollection();
            foreach ($this->routes as $name => $route) {
                $collection->add($name, new SymfonyRoute(
                    $route['path'],
                    [
                        '_controller' => $route['controller'],
                        '_env' => $route['env'],
                        '_requirements' => $route['requirements'],
                    ],
                    $route['requirements'],
                    [],
                    '',
                    [],
                    $route['methods'],
                ));
            }
            $this->collection = $collection;
        }

        return $this->collection;
    }

    public function getMatcher(RequestContext $context): UrlMatcher
    {
        return new UrlMatcher($this->getRouteCollection(), $context);
    }

    public function getControllerLocator(): ContainerInterface
    {
        return $this->controllerLocator;
    }

    /**
     * @return array{lifetime: int, tags: list<string>, ignoreParams: list<string>}|null
     */
    public function getCacheConfig(string $routeName): ?array
    {
        return $this->cacheConfigs[$routeName] ?? null;
    }

    /**
     * @return array{limit: int, interval: string, policy: string}|null
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
     * @return array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}
