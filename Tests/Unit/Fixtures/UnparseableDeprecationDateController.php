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

use KonradMichalik\Typo3Routing\Attribute\{DeprecatedRoute, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * UnparseableDeprecationDateController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class UnparseableDeprecationDateController implements RouteControllerInterface
{
    #[Route(path: '/api/bogus-date', name: 'bogus_date')]
    #[DeprecatedRoute(since: 'not-a-date')]
    public function bogusDate(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
