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
use Symfony\Component\Routing\RouteCollection;
use Throwable;

use function sprintf;

/**
 * RouteCompileGuard.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RouteCompileGuard
{
    /**
     * The dumper compiles every route as a side effect of building the matcher tables; left uncaught,
     * a route with an invalid path or requirement pattern (e.g. non-ASCII without the derived "utf8"
     * option failing to cover some edge case) surfaces a bare Symfony LogicException naming only the
     * path, leaving the operator to guess which of the container's routes is at fault. Compiling here
     * first, one route at a time, lets the rethrow name the route and its controller.
     *
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool}> $routes
     */
    public function assertCompiles(RouteCollection $collection, array $routes): void
    {
        foreach ($collection->all() as $name => $route) {
            try {
                $route->compile();
            } catch (Throwable $exception) {
                $controller = $routes[$name]['controller'] ?? 'unknown controller';

                throw new LogicException(sprintf('Route "%s" (%s) failed to compile: %s', $name, $controller, $exception->getMessage()), 1750000026, $exception);
            }
        }
    }
}
