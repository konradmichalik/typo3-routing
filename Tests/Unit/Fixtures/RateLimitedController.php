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

use KonradMichalik\Typo3Routing\Attribute\{RateLimit, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * RateLimitedController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[RateLimit(limit: 10, interval: '1 minute')]
final class RateLimitedController implements RouteControllerInterface
{
    // No own #[RateLimit]: falls back to the class-level one.
    #[Route(path: '/api/rate-limit/class-level', name: 'rate_limit_class_level')]
    public function classLevel(): JsonResponse
    {
        return new JsonResponse([]);
    }

    // Own #[RateLimit] wins entirely over the class-level one.
    #[Route(path: '/api/rate-limit/method-level', name: 'rate_limit_method_level')]
    #[RateLimit(limit: 5, interval: '10 seconds', policy: 'fixed_window')]
    public function methodLevel(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
