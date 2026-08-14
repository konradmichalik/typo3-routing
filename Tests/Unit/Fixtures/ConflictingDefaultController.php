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

use KonradMichalik\Typo3Routing\Attribute\{Param, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * ConflictingDefaultController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ConflictingDefaultController implements RouteControllerInterface
{
    // The same wire key defaulted on both the method #[Route] and the #[Param] — rejected at build time.
    #[Route(path: '/api/blog/{page}', name: 'conflicting_blog', defaults: ['page' => 5])]
    public function blog(#[Param] int $page = 1): JsonResponse
    {
        return new JsonResponse(['page' => $page]);
    }
}
