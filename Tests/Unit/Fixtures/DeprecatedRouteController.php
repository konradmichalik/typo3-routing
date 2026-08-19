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

use KonradMichalik\Typo3Routing\Attribute\{DeprecatedRoute, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * DeprecatedRouteController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Route(path: '/api/v1', name: 'v1_')]
#[DeprecatedRoute(since: '2026-01-01', sunset: '2026-12-31', successor: 'v2_items', documentation: 'https://example.com/migrate')]
final class DeprecatedRouteController implements RouteControllerInterface
{
    // Inherits the class-level deprecation in full.
    #[Route(path: '/items', name: 'items')]
    public function items(): JsonResponse
    {
        return new JsonResponse([]);
    }

    // The method's own #[DeprecatedRoute] wins entirely over the class-level one.
    #[Route(path: '/minimal', name: 'minimal')]
    #[DeprecatedRoute(since: '2027-01-01')]
    public function minimal(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
