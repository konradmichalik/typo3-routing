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

namespace KonradMichalik\RoutingTest\Controller;

use KonradMichalik\Typo3Routing\Attribute\{Cache, RateLimit, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * ExampleController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ExampleController implements RouteControllerInterface
{
    #[Route(path: '/api/example/count', name: 'example_count')]
    public function count(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['count' => 3]);
    }

    #[Route(path: '/api/example/limited', name: 'example_limited')]
    #[RateLimit(limit: 1, interval: '1 minute', policy: 'sliding_window')]
    public function limited(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['limited' => true]);
    }

    #[Route(path: '/api/example/submit', methods: ['POST'], name: 'example_submit')]
    public function submit(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['submitted' => true]);
    }

    #[Route(path: '/api/example/dev', name: 'example_dev', env: 'Development')]
    public function dev(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['dev' => true]);
    }

    #[Route(path: '/api/example/item/{id}', name: 'example_item', requirements: ['id' => '\d+'])]
    public function item(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['id' => $request->getAttribute('id')]);
    }

    #[Route(path: '/api/example/search', name: 'example_search', requirements: ['q' => '\d+'])]
    public function search(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['q' => $request->getQueryParams()['q'] ?? null]);
    }

    #[Route(path: '/api/example/cached', name: 'example_cached')]
    #[Cache(lifetime: 3600, tags: ['pages'])]
    public function cached(ServerRequestInterface $request): JsonResponse
    {
        // A fresh token each call — identical across requests proves a cache hit.
        return new JsonResponse(['token' => bin2hex(random_bytes(8))]);
    }
}
