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
 * ClassBaseParamController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Route(path: '/api/v2', name: 'v2_', requirements: ['id' => '\d+'])]
final class ClassBaseParamController implements RouteControllerInterface
{
    // A class-level requirement is only a base: the #[Param] on the method parameter overrides it.
    #[Route(path: '/items/{id}', name: 'items_show')]
    public function show(#[Param(requirement: '[a-z]+')] string $id): JsonResponse
    {
        return new JsonResponse(['id' => $id]);
    }
}
