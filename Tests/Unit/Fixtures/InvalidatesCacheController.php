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

use KonradMichalik\Typo3Routing\Attribute\{InvalidatesCache, Route};
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * InvalidatesCacheController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class InvalidatesCacheController implements RouteControllerInterface
{
    #[Route(path: '/news', name: 'news_create', methods: ['POST'])]
    #[InvalidatesCache(tags: ['tx_news_domain_model_news', 'news_list'])]
    public function create(): JsonResponse
    {
        return new JsonResponse([], 201);
    }

    #[Route(path: '/news', name: 'news_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
