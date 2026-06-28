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

use KonradMichalik\Typo3Routing\Attribute\{Authenticate, Cache, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Authentication\PassAuthenticator;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * CachedAuthenticatedController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class CachedAuthenticatedController implements RouteControllerInterface
{
    #[Route(path: '/api/secure-cached', name: 'fixture_secure_cached')]
    #[Authenticate(PassAuthenticator::class)]
    #[Cache(lifetime: 60, tags: ['pages'])]
    public function secureCached(): JsonResponse
    {
        return new JsonResponse(['secure' => true]);
    }
}
