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

use KonradMichalik\Typo3Routing\Attribute\{Cache, Param, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * CacheIgnoresUndeclaredParamController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class CacheIgnoresUndeclaredParamController implements RouteControllerInterface
{
    // "search" is only the PHP name; the wire name is "q", so the cache key still varies on it.
    #[Route(path: '/api/search', name: 'cache_ignores_undeclared')]
    #[Cache(lifetime: 60, ignoreParams: ['utm_source', 'search'])]
    public function search(#[Param(name: 'q')] string $search = ''): JsonResponse
    {
        return new JsonResponse(['search' => $search]);
    }

    #[Route(path: '/api/feed', name: 'cache_ignores_undeclared_request')]
    #[Cache(lifetime: 60, ignoreParams: ['utm_source'])]
    public function feed(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['path' => $request->getUri()->getPath()]);
    }
}
