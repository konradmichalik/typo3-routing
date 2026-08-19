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
use ReflectionMethod;

use function sprintf;

/**
 * EmptyPathGuard.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class EmptyPathGuard
{
    /**
     * Symfony\Component\Routing\Route::setPath() silently normalizes an empty path to "/", which
     * would claim the site root ahead of TYPO3's own page rendering.
     */
    public function assertNotEmpty(string $path, string $name, string $serviceId, ReflectionMethod $method): void
    {
        if ('' !== $path) {
            return;
        }

        throw new LogicException(sprintf('Route "%s" (%s::%s()) resolves to an empty path, which Symfony normalizes to "/" and would claim the site root. Give the class a #[Route] prefix, or make the method path non-empty.', $name, $serviceId, $method->getName()), 1750000032);
    }
}
