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
 * InheritedRouteController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
abstract class InheritedRouteController implements RouteControllerInterface
{
    // Final: PHP does not carry method attributes onto an override, so overriding this method
    // would drop the #[Route] and #[Cache] below without any error.
    #[Route(path: '', name: 'list')]
    #[Cache(lifetime: 90, tags: ['tx_inherited'])]
    final public function list(#[Param(requirement: '\d+')] int $page = 1): JsonResponse
    {
        return new JsonResponse(['page' => $page]);
    }

    #[Route(path: '/{uid}', name: 'detail', requirements: ['uid' => '\d+'])]
    final public function detail(int $uid): JsonResponse
    {
        return new JsonResponse(['uid' => $uid]);
    }
}
