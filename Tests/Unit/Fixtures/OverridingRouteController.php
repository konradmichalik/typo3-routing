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

use KonradMichalik\Typo3Routing\Attribute\Route;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * OverridingRouteController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Route(path: '/api/overriding', name: 'overriding_')]
final class OverridingRouteController extends OverridableRouteController
{
    // Overrides the parent without repeating its attributes: the route ceases to exist.
    public function detail(int $uid): JsonResponse
    {
        return new JsonResponse(['uid' => $uid, 'overridden' => true]);
    }
}
