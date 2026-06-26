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

use KonradMichalik\Typo3Routing\Cache\ResponseCacheManager;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * CreatesResponseCacheManager.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
trait CreatesResponseCacheManager
{
    private function createResponseCacheManager(): ResponseCacheManager
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn(new InMemoryCacheFrontend());

        return new ResponseCacheManager($cacheManager);
    }
}
