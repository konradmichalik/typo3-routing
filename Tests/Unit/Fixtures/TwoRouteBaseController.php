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
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;

/**
 * TwoRouteBaseController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
abstract class TwoRouteBaseController implements RouteControllerInterface
{
    #[Route(path: '/a', name: 'route_a')]
    public function a(): void {}

    #[Route(path: '/b', name: 'route_b')]
    public function b(): void {}
}
