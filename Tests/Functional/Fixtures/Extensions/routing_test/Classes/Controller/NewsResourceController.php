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

namespace KonradMichalik\RoutingTest\Controller;

use KonradMichalik\Typo3Routing\Attribute\Route;

/**
 * NewsResourceController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Route(path: '/api/news', name: 'news_')]
final class NewsResourceController extends AbstractResourceController
{
    protected function resource(): string
    {
        return 'news';
    }
}
