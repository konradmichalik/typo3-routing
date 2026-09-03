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
     * `requirements` and `defaults` say the same thing explicitly, so the inline forms are rejected
     * here rather than parsed a second time.
     */
    public function assertSupported(string $path, string $name, string $serviceId, ReflectionMethod $method): void
    {
        $offenders = PlaceholderSyntax::unsupported($path);
        if ([] === $offenders) {
            return;
        }

        throw new LogicException(sprintf('Route "%s" (%s::%s()) uses unsupported placeholder syntax in path "%s": "%s". Only the plain "{name}" form binds a controller argument; the inline forms match but would read the argument from the query/body instead of the path. Use the #[Route] "requirements" parameter for a constraint and "defaults" for a default value.', $name, $serviceId, $method->getName(), $path, implode('", "', $offenders)), 1750000039);
    }
}
