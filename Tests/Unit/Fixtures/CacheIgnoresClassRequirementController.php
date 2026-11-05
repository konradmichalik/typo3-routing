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

use KonradMichalik\Typo3Routing\Attribute\{Cache, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * CacheIgnoresClassRequirementController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Route(path: '/api/items', name: 'class_req_', requirements: ['id' => '\d+', 'sort' => 'asc|desc'])]
final class CacheIgnoresClassRequirementController implements RouteControllerInterface
{
    // The placeholder is part of the cache key, so ignoring it is harmless.
    #[Route(path: '/{id}', name: 'show')]
    #[Cache(lifetime: 60, ignoreParams: ['id'])]
    public function show(int $id): JsonResponse
    {
        return new JsonResponse(['id' => $id]);
    }

    #[Route(path: '', name: 'list')]
    #[Cache(lifetime: 60, ignoreParams: ['sort'])]
    public function list(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
