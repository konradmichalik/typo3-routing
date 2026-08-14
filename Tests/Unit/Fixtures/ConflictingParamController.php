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
 * ConflictingParamController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ConflictingParamController implements RouteControllerInterface
{
    // The same wire key constrained on both the method #[Route] and the #[Param] — rejected at build time.
    #[Route(path: '/api/item/{id}', name: 'conflicting_item', requirements: ['id' => '\d+'])]
    public function show(#[Param(requirement: '[a-z]+')] string $id): JsonResponse
    {
        return new JsonResponse(['id' => $id]);
    }
}
