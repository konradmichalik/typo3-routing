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

use KonradMichalik\Typo3Routing\Cache\{CacheTagFlusher, ResponseCacheManager};
use KonradMichalik\Typo3Routing\RateLimit\CacheStorage;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * Configuration.
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
     * Register the response cache (uses the TYPO3 default backend); part of the "all" group so
     * "Flush all caches" clears it. Per-route invalidation is tag-based.
     */
    public static function registerResponseCache(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][ResponseCacheManager::CACHE_IDENTIFIER] ??= [
            'frontend' => VariableFrontend::class,
            'groups' => ['all'],
        ];
    }

    /**
     * Register the rate-limit bucket store. Deliberately kept out of every cache group so a
     * "Flush all caches" cannot reset live rate-limit counters; buckets expire on their own TTL.
     */
    public static function registerRateLimitCache(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][CacheStorage::CACHE_IDENTIFIER] ??= [
            'frontend' => VariableFrontend::class,
        ];
    }

    /**
     * Flush tagged response cache entries when a record of a tagged table changes.
     */
    public static function registerCacheInvalidation(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][ResponseCacheManager::CACHE_IDENTIFIER] = CacheTagFlusher::class;
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][ResponseCacheManager::CACHE_IDENTIFIER] = CacheTagFlusher::class;
    }
}
