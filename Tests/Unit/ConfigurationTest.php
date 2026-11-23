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

use KonradMichalik\Ttt\Attribute\WithTypo3ConfVars;
use KonradMichalik\Typo3Routing\Cache\ResponseCacheManager;
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
#[WithTypo3ConfVars([])]
final class ConfigurationTest extends TestCase
{
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
        Configuration::registerResponseCache();

        /** @var array{frontend: string, groups: list<string>} $config */
        $config = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][ResponseCacheManager::CACHE_IDENTIFIER];
        self::assertSame(VariableFrontend::class, $config['frontend']);
        self::assertSame(['pages', 'all'], $config['groups']);
    }

    #[Test]
    public function addsThePagesGroupToAPreconfiguredResponseCacheWithoutGroups(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][ResponseCacheManager::CACHE_IDENTIFIER] = ['backend' => 'RedisBackend'];

        Configuration::registerResponseCache();

        /** @var array{backend: string, frontend: string, groups: list<string>} $config */
        $config = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][ResponseCacheManager::CACHE_IDENTIFIER];
        self::assertSame('RedisBackend', $config['backend']);
        self::assertSame(VariableFrontend::class, $config['frontend']);
        self::assertSame(['pages', 'all'], $config['groups']);
    }

    #[Test]
    public function keepsExplicitlyConfiguredResponseCacheGroups(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][ResponseCacheManager::CACHE_IDENTIFIER] = ['groups' => ['all']];

        Configuration::registerResponseCache();

        /** @var array{groups: list<string>} $config */
        $config = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][ResponseCacheManager::CACHE_IDENTIFIER];
        self::assertSame(['all'], $config['groups']);
    }

    #[Test]
    public function registersTheRateLimitCacheWithoutExplicitGroups(): void
    {
        Configuration::registerRateLimitCache();

        /** @var array{frontend: string} $config */
        $config = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][CacheStorage::CACHE_IDENTIFIER];
        self::assertSame(VariableFrontend::class, $config['frontend']);
        // No "groups" key: TYPO3 then assigns only "all", so clearing the page caches keeps live buckets.
        self::assertArrayNotHasKey('groups', $config);
    }
}
