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

use KonradMichalik\Typo3Routing\Attribute\{Cache, RateLimit};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * OrphanedModifierController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class OrphanedModifierController implements RouteControllerInterface
{
    #[Cache(lifetime: 120)]
    #[RateLimit(limit: 5, interval: '10 seconds', policy: 'fixed_window')]
    public function orphaned(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
