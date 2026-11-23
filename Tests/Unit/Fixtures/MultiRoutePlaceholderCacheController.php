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

use KonradMichalik\Typo3Routing\Attribute\{Cache, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * MultiRoutePlaceholderCacheController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MultiRoutePlaceholderCacheController implements RouteControllerInterface
{
    #[Route(path: '/news/{id}', name: 'news_detail')]
    #[Route(path: '/news/latest', name: 'news_latest')]
    #[Cache(lifetime: 60, tags: ['news_{id}'])]
    public function detail(int $id = 0): JsonResponse
    {
        return new JsonResponse(['id' => $id]);
    }
}
