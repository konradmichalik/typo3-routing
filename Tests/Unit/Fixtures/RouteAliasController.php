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

/**
 * RouteAliasController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RouteAliasController implements RouteControllerInterface
{
    #[Route(path: '/api/aliased-count', name: 'aliased_count', aliases: ['legacy_count', 'old_count'])]
    public function count(): JsonResponse
    {
        return new JsonResponse(['count' => 3]);
    }
}
