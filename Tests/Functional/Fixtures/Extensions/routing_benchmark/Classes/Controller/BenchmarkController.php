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

namespace KonradMichalik\RoutingBenchmark\Controller;

use KonradMichalik\RoutingBenchmark\Domain\Model\Item;
use KonradMichalik\Typo3Routing\Attribute\{Cache, DeprecatedRoute, RateLimit, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

// The "typo3-routing" side of the benchmark. Every endpoint here has a byte-for-byte identical
// counterpart served by PlainBenchmarkMiddleware, so the measured difference isolates the cost
// of the attribute-routing layer (Symfony matcher, registry lookup, argument resolution)
// against a hand-rolled middleware.

/**
 * BenchmarkController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class BenchmarkController implements RouteControllerInterface
{
    #[Route(path: '/api/bench/routing/noop', name: 'bench_routing_noop')]
    public function noop(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    #[Route(path: '/api/bench/routing/item/{id}', name: 'bench_routing_item', requirements: ['id' => '\d+'])]
    public function item(int $id): JsonResponse
    {
        // Path placeholder resolved + type-cast to int by the routing layer.
        return new JsonResponse(['id' => $id]);
    }

    #[Route(path: '/api/bench/routing/search', name: 'bench_routing_search', requirements: ['q' => '\d+'])]
    public function search(int $q): JsonResponse
    {
        // Query parameter resolved + type-cast to int by the routing layer.
        return new JsonResponse(['q' => $q]);
    }

    #[Route(path: '/api/bench/routing/entity/{item}', name: 'bench_routing_entity', requirements: ['item' => '\d+'])]
    public function entity(Item $item): JsonResponse
    {
        // Path placeholder resolved directly to a hydrated Extbase domain object by the routing layer.
        return new JsonResponse(['id' => $item->getUid(), 'title' => $item->getTitle()]);
    }

    // Measured from two directions: requested in its declared casing it must cost the same as any
    // other static route (the tolerance is a fallback, so an exact hit never reaches it), requested
    // in a different casing it pays the whole fallback chain: failed compiled match, failed
    // trailing-slash retry, then the non-compiled UrlMatcher over the opted-in collection.
    #[Route(path: '/api/bench/routing/ci', name: 'bench_routing_ci', caseInsensitive: true)]
    public function caseInsensitive(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    // v1.2.0: cost of DeprecationHeaders::decorate() on every response from this route — Deprecation,
    // Sunset and two Link entries (successor resolved through RouteUrlGenerator, documentation a plain
    // string), against a route without #[DeprecatedRoute] answering the identical body.
    #[Route(path: '/api/bench/routing/deprecated', name: 'bench_routing_deprecated')]
    #[DeprecatedRoute(since: '2026-01-01', sunset: '2027-01-01', successor: 'bench_routing_noop', documentation: 'https://example.com/docs/deprecated')]
    public function deprecated(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    // v1.2.0: cost of the site/language visibility check (SiteLanguageScope::isMatchVisible() inside
    // RouteMatcher::match()), paid on a route that opted in and is requested in scope.
    #[Route(path: '/api/bench/routing/scoped', name: 'bench_routing_scoped', sites: ['main'], languages: [0])]
    public function scoped(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    // v1.2.0: `canonical: true` measured from two directions, mirroring how `caseInsensitive` is
    // measured above. Requested at the declared path it must cost the same as any other static route
    // (canonicalRedirect() sees `_canonicalVariant === false` and returns immediately). Requested via
    // the tolerated trailing-slash variant it pays the whole redirect build: RouteUrlGenerator::generate()
    // plus a 308 Response construction.
    #[Route(path: '/api/bench/routing/canonical', name: 'bench_routing_canonical', canonical: true)]
    public function canonical(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    // v1.2.0: a legacy path redirecting to its current route (the default; `legacyAlias` not set),
    // same redirect mechanics as `canonical` above but taken through legacyRedirect() instead.
    #[Route(path: '/api/bench/routing/legacy-new', name: 'bench_routing_legacy', legacyPaths: ['/api/bench/routing/legacy-old'])]
    public function legacy(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    // v1.2.0: `legacyAlias: true` — the old path is served directly instead of redirecting, so
    // legacyRedirect() short-circuits on registry->isLegacyAlias() and this measures the same as a
    // plain static route reached through its declared path.
    #[Route(path: '/api/bench/routing/alias-new', name: 'bench_routing_alias', legacyPaths: ['/api/bench/routing/alias-old'], legacyAlias: true)]
    public function legacyAliasRoute(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    // #[Cache] measured on a warm entry: after the warmup every request is a HIT, so this pair costs the
    // cache key (built from the coerced `page` through the argument resolver) plus one cache read and
    // the response rebuild. See PlainBenchmarkMiddleware::cached() for what the plain side does.
    #[Route(path: '/api/bench/routing/cached', name: 'bench_routing_cached')]
    #[Cache(lifetime: 3600)]
    public function cached(int $page = 1): JsonResponse
    {
        return new JsonResponse(['page' => $page]);
    }

    // #[RateLimit] with a limit no benchmark run can reach, so the pair costs one limiter consume on the
    // cache-backed bucket store plus the quota headers, and never a 429.
    #[Route(path: '/api/bench/routing/ratelimited', name: 'bench_routing_ratelimited')]
    #[RateLimit(limit: 100000, interval: '1 minute')]
    public function rateLimited(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
