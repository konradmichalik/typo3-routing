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

use KonradMichalik\Typo3Routing\Routing\PlaceholderSyntax;
use LogicException;
use ReflectionMethod;

use function implode;
use function sprintf;

/**
 * PlaceholderSyntaxGuard.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PlaceholderSyntaxGuard
{
    /**
     * Symfony's inline placeholder forms (`{id<\d+>}`, `{page?1}`, `{!page}`, `{user:id}`) compile
     * and match, but `ArgumentSpecFactory` only recognises the plain `{name}` form as a path
     * placeholder — so the controller argument would silently be read from the query/body instead.
     * Every form has an explicit equivalent (`requirements`, `defaults`, a plain `{name}`, or a typed
     * parameter), so the inline forms are rejected here rather than parsed a second time.
     */
    public function assertSupported(string $path, string $name, string $serviceId, ReflectionMethod $method): void
    {
        $offenders = PlaceholderSyntax::unsupported($path);
        if ([] === $offenders) {
            return;
        }

        throw new LogicException(sprintf('Route "%s" (%s::%s()) uses unsupported placeholder syntax in path "%s": "%s". Only the plain "{name}" form binds a controller argument; the inline forms match but would read the argument from the query/body instead of the path. Write the plain "{name}" form and declare the rest explicitly; the replacement for each inline form is listed under "Inline placeholder syntax" in docs/routes/route-attribute.md.', $name, $serviceId, $method->getName(), $path, implode('", "', $offenders)), 1750000039);
    }
}
