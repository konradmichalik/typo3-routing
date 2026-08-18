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

use KonradMichalik\Typo3Routing\Routing\{CaseInsensitiveRouteCompiler, RouteRegistry};
use LogicException;
use Symfony\Component\Routing\Exception\{MethodNotAllowedException, ResourceNotFoundException};
use Symfony\Component\Routing\Matcher\{UrlMatcher, UrlMatcherInterface};
use Symfony\Component\Routing\RequestContext;

use function sprintf;
use function str_ends_with;
use function substr;

/**
 * LegacyPathValidator.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class LegacyPathValidator
{
    /**
     * A legacy path must be unambiguous: it may not overlap with any path a declared route could match —
     * not just an identical string, but also a declared placeholder route, a declared route's
     * trailing-slash-tolerated variant, or a declared #[Route(caseInsensitive: true)] route matching it
     * in another case — since the primary matcher (and the case-insensitive one) runs before legacy
     * matching and would claim the request first, silently defeating the configured legacy behaviour. Nor
     * may two routes both claim the same legacy path.
     */
    public function assertNoCollisions(CollectedRoutes $collected): void
    {
        $declaredPaths = [];
        foreach ($collected->routes as $route) {
            $declaredPaths[$route['path']] = true;
        }

        [$matcher, $caseInsensitiveMatcher] = $this->buildMatchers($collected);

        $claimedBy = [];
        foreach ($collected->routes as $name => $route) {
            foreach ($route['legacyPaths'] ?? [] as $legacyPath) {
                $this->assertLegacyPathIsFree($legacyPath, $name, $declaredPaths, $matcher, $caseInsensitiveMatcher, $claimedBy);
                $claimedBy[$legacyPath] = $name;
            }
        }
    }

    /**
     * @return array{0: UrlMatcherInterface, 1: UrlMatcherInterface|null}
     */
    private function buildMatchers(CollectedRoutes $collected): array
    {
        $matcher = new UrlMatcher(RouteRegistry::buildCollection($collected->routes), new RequestContext());

        $caseInsensitiveCollection = RouteRegistry::buildCollection(RouteRegistry::caseInsensitiveRoutes($collected->routes));
        foreach ($caseInsensitiveCollection->all() as $route) {
            $route->setOption('compiler_class', CaseInsensitiveRouteCompiler::class);
        }
        $caseInsensitiveMatcher = 0 === $caseInsensitiveCollection->count() ? null : new UrlMatcher($caseInsensitiveCollection, new RequestContext());

        return [$matcher, $caseInsensitiveMatcher];
    }

    /**
     * @param array<string, true>  $declaredPaths
     * @param array<string, string> $claimedBy
     */
    private function assertLegacyPathIsFree(string $legacyPath, string $name, array $declaredPaths, UrlMatcherInterface $matcher, ?UrlMatcherInterface $caseInsensitiveMatcher, array $claimedBy): void
    {
        if (isset($declaredPaths[$legacyPath])) {
            throw new LogicException(sprintf('Legacy path "%s" on route "%s" collides with a declared #[Route] path. Legacy paths must not overlap with any current route.', $legacyPath, $name), 1750000035);
        }

        if ($this->overlapsAMatcher($matcher, $legacyPath) || (null !== $caseInsensitiveMatcher && $this->overlapsAMatcher($caseInsensitiveMatcher, $legacyPath))) {
            throw new LogicException(sprintf('Legacy path "%s" on route "%s" overlaps with a declared #[Route] path (a placeholder, a trailing-slash variant, or a #[Route(caseInsensitive: true)] route matching it in another case). The primary matcher runs before legacy paths are ever consulted, so this legacy path could never be reached.', $legacyPath, $name), 1750000035);
        }

        if (isset($claimedBy[$legacyPath])) {
            throw new LogicException(sprintf('Legacy path "%s" is claimed by both route "%s" and route "%s". A legacy path must belong to exactly one route.', $legacyPath, $claimedBy[$legacyPath], $name), 1750000036);
        }
    }

    /**
     * True when `$path`, or its trailing-slash-toggled variant, matches some declared route regardless of
     * HTTP method: a `MethodNotAllowedException` still means the path itself is claimed, so it counts as
     * an overlap exactly like a successful match — only a plain miss on both variants is not one.
     */
    private function overlapsAMatcher(UrlMatcherInterface $matcher, string $path): bool
    {
        foreach ([$path, $this->trailingSlashVariant($path)] as $candidate) {
            if (null === $candidate) {
                continue;
            }

            try {
                $matcher->match($candidate);

                return true;
            } catch (MethodNotAllowedException) {
                return true;
            } catch (ResourceNotFoundException) {
                // Not a match for this candidate — try the other one, if any.
            }
        }

        return false;
    }

    private function trailingSlashVariant(string $path): ?string
    {
        if (!str_ends_with($path, '/')) {
            return $path.'/';
        }

        return '/' === $path ? null : substr($path, 0, -1);
    }
}
