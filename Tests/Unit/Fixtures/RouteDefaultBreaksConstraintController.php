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

use KonradMichalik\Typo3Routing\Attribute\{Param, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * RouteDefaultBreaksConstraintController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RouteDefaultBreaksConstraintController implements RouteControllerInterface
{
    #[Route(path: '/api/items/{limit}', name: 'route_default_limit', defaults: ['limit' => 500])]
    public function list(#[Param(max: 100)] int $limit): JsonResponse
    {
        return new JsonResponse(['limit' => $limit]);
    }
}
