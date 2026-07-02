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
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * PrefixedController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Route(path: '/api/v1', name: 'v1_', env: 'Development', requirements: ['id' => '\d+'])]
final class PrefixedController implements RouteControllerInterface
{
    #[Route(path: '/items/{id}', name: 'items_show')]
    public function show(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['item' => true]);
    }

    // Method env overrides the class default; method requirement overrides the class base per key.
    #[Route(path: '/ping', env: 'Production', requirements: ['id' => '[a-z]+'])]
    public function ping(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse(['ping' => true]);
    }
}
