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
 * DuplicateAliasController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class DuplicateAliasController implements RouteControllerInterface
{
    #[Route(path: '/api/a', name: 'route_a', aliases: ['shared_alias'])]
    public function a(): JsonResponse
    {
        return new JsonResponse([]);
    }

    #[Route(path: '/api/b', name: 'route_b', aliases: ['shared_alias'])]
    public function b(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
