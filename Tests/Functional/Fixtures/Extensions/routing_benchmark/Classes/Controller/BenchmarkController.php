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
use KonradMichalik\Typo3Routing\Attribute\Route;
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
}
