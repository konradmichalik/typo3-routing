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
 * InvalidTagCacheController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class InvalidTagCacheController implements RouteControllerInterface
{
    #[Route(path: '/news', name: 'news_list')]
    #[Cache(lifetime: 60, tags: ['news_list', 'news.item'])]
    public function list(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
