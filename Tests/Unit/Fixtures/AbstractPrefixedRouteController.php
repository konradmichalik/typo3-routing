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
use TYPO3\CMS\Core\Http\JsonResponse;

// A subclass does not inherit this class-level #[Route]: ReflectionClass::getAttributes()
// reports a class's own attributes only, so its prefix and the collision protection it
// would provide are both lost on the subclass.
/**
 * AbstractPrefixedRouteController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Route(path: '/api/base', name: 'base_')]
abstract class AbstractPrefixedRouteController implements RouteControllerInterface
{
    #[Route(path: '/ping', name: 'inherited_ping')]
    public function ping(): JsonResponse
    {
        return new JsonResponse(['ping' => true]);
    }
}
