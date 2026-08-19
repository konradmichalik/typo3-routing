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

use KonradMichalik\Typo3Routing\Attribute\Route;
use LogicException;
use ReflectionMethod;
use Symfony\Component\Routing\Route as SymfonyRoute;

use function sprintf;
use function str_ends_with;

/**
 * ClassExclusiveResolver.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ClassExclusiveResolver
{
    /**
     * The class's own static path prefix when it opted into #[Route(exclusive: true)], or null when it
     * did not. Computed once per class and threaded through every one of its method routes, rather than
     * recomputed from each method's own (longer, more specific) composed path.
     *
     * Always returned with a trailing slash: the claim is matched with a plain string-prefix check
     * (PathPrefixGate::matches()), so without one, "/api/exclusive" would also claim an unrelated
     * sibling like "/api/exclusive-other" that merely starts with the same characters. The slash keeps
     * the claim bound to the class's own path segment without needing a separate matching mode.
     */
    public function resolvePrefix(?Route $classRoute, string $serviceId): ?string
    {
        if (!$classRoute instanceof Route || true !== $classRoute->exclusive) {
            return null;
        }

        $prefix = (new SymfonyRoute($classRoute->path))->compile()->getStaticPrefix();
        if ('' === $prefix || '/' === $prefix) {
            throw new LogicException(sprintf('#[Route(exclusive: true)] on "%s" would claim every unmatched path site-wide: its own path "%s" resolves to no more than the root prefix (it starts with a placeholder, is empty, or is just "/"). Give the class a literal, non-root leading path segment, or drop "exclusive".', $serviceId, $classRoute->path), 1750000033);
        }

        return str_ends_with($prefix, '/') ? $prefix : $prefix.'/';
    }

    /**
     * "exclusive" only makes sense as a claim over a shared class prefix; on a single method route there
     * is no "rest of the prefix" left to turn into a 404, so it would silently do nothing.
     */
    public function assertNotOnMethod(Route $route, ReflectionMethod $method, string $serviceId): void
    {
        if (true !== $route->exclusive) {
            return;
        }

        throw new LogicException(sprintf('#[Route(exclusive: true)] on "%s::%s()" has no effect on a method route; "exclusive" is a class-level-only setting. Move it to the class-level #[Route].', $serviceId, $method->getName()), 1750000032);
    }
}
