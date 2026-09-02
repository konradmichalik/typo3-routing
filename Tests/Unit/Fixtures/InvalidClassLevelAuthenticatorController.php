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
 * InvalidClassLevelAuthenticatorController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Authenticate(PlainService::class)]
final class InvalidClassLevelAuthenticatorController implements RouteControllerInterface
{
    // No own #[Authenticate]: the invalid class-level one must still surface as a build-time error.
    #[Route(path: '/api/broken-class', name: 'fixture_broken_class')]
    public function broken(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
