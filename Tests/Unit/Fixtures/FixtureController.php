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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Fixtures;

use KonradMichalik\Typo3Routing\Attribute\{Cache, RateLimit, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * FixtureController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class FixtureController implements RouteControllerInterface
{
    #[Route(path: '/api/count', name: 'fixture_count')]
    #[Cache(lifetime: 120, tags: ['tx_fixture'])]
    public function count(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['count' => 42]);
    }

    #[Route(path: '/api/limited', name: 'fixture_limited')]
    #[RateLimit(limit: 5, interval: '10 seconds', policy: 'fixed_window')]
    public function limited(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['limited' => true]);
    }

    #[Route(path: '/api/results', methods: ['GET', 'POST'])]
    public function results(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['results' => []]);
    }

    #[Route(path: '/api/dev', name: 'fixture_dev', env: 'Development')]
    public function dev(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['dev' => true]);
    }

    #[Route(path: '/api/alias-a', name: 'fixture_alias_a')]
    #[Route(path: '/api/alias-b', name: 'fixture_alias_b')]
    public function repeatable(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['repeatable' => true]);
    }

    public function withoutRoute(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse([]);
    }
}
