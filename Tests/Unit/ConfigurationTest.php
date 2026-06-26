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

namespace KonradMichalik\Typo3Routing\Tests\Unit;

use KonradMichalik\Typo3Routing\Cache\{CacheTagFlusher, ResponseCacheManager};
use KonradMichalik\Typo3Routing\Configuration;
use KonradMichalik\Typo3Routing\RateLimit\CacheStorage;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * ConfigurationTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['namespaces']['routing'],
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][ResponseCacheManager::CACHE_IDENTIFIER],
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][CacheStorage::CACHE_IDENTIFIER],
            $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][ResponseCacheManager::CACHE_IDENTIFIER],
            $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][ResponseCacheManager::CACHE_IDENTIFIER],
        );
    }

    #[Test]
    public function registersTheRoutingFluidNamespace(): void
    {
        Configuration::registerFluidNamespace();

        /** @var list<string> $namespaces */
        $namespaces = $GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['namespaces']['routing'];
        self::assertContains('KonradMichalik\\Typo3Routing\\ViewHelpers', $namespaces);
    }

    #[Test]
    public function registersTheResponseCache(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][ResponseCacheManager::CACHE_IDENTIFIER]);

        Configuration::registerResponseCache();

        /** @var array{frontend: string, groups: list<string>} $config */
        $config = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][ResponseCacheManager::CACHE_IDENTIFIER];
        self::assertSame(VariableFrontend::class, $config['frontend']);
        self::assertSame(['all'], $config['groups']);
    }

    #[Test]
    public function registersTheRateLimitCacheOutsideTheAllGroup(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][CacheStorage::CACHE_IDENTIFIER]);

        Configuration::registerRateLimitCache();

        /** @var array{frontend: string} $config */
        $config = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][CacheStorage::CACHE_IDENTIFIER];
        self::assertSame(VariableFrontend::class, $config['frontend']);
        // Not part of the "all" group: clearing all caches must not reset live rate-limit buckets.
        self::assertArrayNotHasKey('groups', $config);
    }

    #[Test]
    public function registersTheCacheInvalidationHooks(): void
    {
        Configuration::registerCacheInvalidation();

        $hooks = $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php'];
        self::assertSame(CacheTagFlusher::class, $hooks['processDatamapClass'][ResponseCacheManager::CACHE_IDENTIFIER]);
        self::assertSame(CacheTagFlusher::class, $hooks['processCmdmapClass'][ResponseCacheManager::CACHE_IDENTIFIER]);
    }
}
