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
use KonradMichalik\Typo3Routing\Routing\ControllerArgumentResolver;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * CreatesResponseCacheManager.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
trait CreatesResponseCacheManager
{
    private function createResponseCacheManager(?PersistenceManagerInterface $persistenceManager = null): ResponseCacheManager
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn(new InMemoryCacheFrontend());

        return new ResponseCacheManager($cacheManager, new ControllerArgumentResolver($persistenceManager ?? $this->createMock(PersistenceManagerInterface::class)));
    }
}
