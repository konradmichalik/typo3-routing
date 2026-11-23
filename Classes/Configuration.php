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

namespace KonradMichalik\Typo3Routing;

use KonradMichalik\Typo3Routing\Cache\ResponseCacheManager;
use KonradMichalik\Typo3Routing\RateLimit\CacheStorage;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * Configuration.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class Configuration
{
    /**
     * Make `{routing:uri(...)}` available in every Fluid template.
     */
    public static function registerFluidNamespace(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['namespaces']['routing'][] = 'KonradMichalik\\Typo3Routing\\ViewHelpers';
    }

    /**
     * Register the response cache (uses the TYPO3 default backend). Group "pages" lets the core
     * DataHandler flush its `{table}` and `{table}_{uid}` tags and makes "Flush frontend caches" clear it.
     * Filled in key by key, so a preconfigured backend keeps the group; explicitly set groups win.
     */
    public static function registerResponseCache(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][ResponseCacheManager::CACHE_IDENTIFIER]['frontend'] ??= VariableFrontend::class;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][ResponseCacheManager::CACHE_IDENTIFIER]['groups'] ??= ['pages', 'all'];
    }

    /**
     * Register the rate-limit bucket store. Without a "groups" key it lands in TYPO3's default group "all"
     * only, so clearing the page caches keeps live counters; "Flush all caches" empties it.
     */
    public static function registerRateLimitCache(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][CacheStorage::CACHE_IDENTIFIER] ??= [
            'frontend' => VariableFrontend::class,
        ];
    }
}
