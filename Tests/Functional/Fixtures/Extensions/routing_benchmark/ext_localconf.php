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

use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

defined('TYPO3') || exit;

// The plain side's own stores, configured exactly like the extension's (VariableFrontend, default backend),
// so the pairs differ in the work around the cache and not in what backs it.
foreach (['routing_benchmark_plain_response', 'routing_benchmark_plain_ratelimit'] as $identifier) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][$identifier] ??= [
        'frontend' => VariableFrontend::class,
    ];
}
