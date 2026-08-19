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

use LogicException;

use function sprintf;

/**
 * RouteAliasCollector.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RouteAliasCollector
{
    /**
     * Collects one route's aliases, failing fast when two routes declare the same alias name — the
     * only alias-vs-alias collision detectable during discovery, since every route's own name is
     * already known by the time it is stored. Whether an alias collides with a route name is checked
     * separately, once every route has been discovered (see assertNoCollisionWithRoutes()).
     *
     * @param list<string> $aliases
     */
    public function apply(array $aliases, string $namePrefix, string $routeName, string $serviceId, string $methodName, CollectedRoutes $collected): void
    {
        foreach ($aliases as $alias) {
            $prefixedAlias = $namePrefix.$alias;
            if (isset($collected->aliases[$prefixedAlias]) && $collected->aliases[$prefixedAlias] !== $routeName) {
                throw new LogicException(sprintf('Route alias "%s" is declared by both "%s" and "%s" (%s::%s()). Each alias name must be unique.', $prefixedAlias, $collected->aliases[$prefixedAlias], $routeName, $serviceId, $methodName), 1750000033);
            }
            $collected->aliases[$prefixedAlias] = $routeName;
        }
    }

    /**
     * `RouteCollection::addAlias()` silently removes any real route sharing the alias's name instead
     * of rejecting it, so this extension enforces the guard itself — extending the same "duplicate
     * name fails the build" rule `RouteCompilerPass::storeRoute()` already applies to routes. A route
     * aliasing its own name is naturally caught here too: it collides with itself.
     */
    public function assertNoCollisionWithRoutes(CollectedRoutes $collected): void
    {
        foreach ($collected->aliases as $alias => $owner) {
            if (isset($collected->routes[$alias])) {
                throw new LogicException(sprintf('Route alias "%s" declared by "%s" collides with an existing route of the same name.', $alias, $owner), 1750000032);
            }
        }
    }
}
