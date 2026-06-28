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

use KonradMichalik\Typo3Routing\Attribute\{Authenticate, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * InvalidAuthenticatorController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class InvalidAuthenticatorController implements RouteControllerInterface
{
    #[Route(path: '/api/broken', name: 'fixture_broken')]
    #[Authenticate(PlainService::class)]
    public function broken(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
