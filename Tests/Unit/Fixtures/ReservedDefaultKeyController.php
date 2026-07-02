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
 * ReservedDefaultKeyController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ReservedDefaultKeyController implements RouteControllerInterface
{
    #[Route(path: '/api/reserved', name: 'reserved', defaults: ['_env' => 'Production'])]
    public function reserved(ServerRequestInterface $request): JsonResponse
    {
        return new JsonResponse([]);
    }
}
