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
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * CacheIgnoresConstrainedParamController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class CacheIgnoresConstrainedParamController implements RouteControllerInterface
{
    #[Route(path: '/api/search', name: 'cache_ignores_constrained')]
    #[Cache(lifetime: 60, ignoreParams: ['search'])]
    public function search(#[Param(maxLength: 50)] string $search = ''): JsonResponse
    {
        return new JsonResponse(['search' => $search]);
    }
}
