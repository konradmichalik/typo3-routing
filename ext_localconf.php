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

defined('TYPO3') || exit;

KonradMichalik\Typo3Routing\Configuration::registerFluidNamespace();
KonradMichalik\Typo3Routing\Configuration::registerResponseCache();
KonradMichalik\Typo3Routing\Configuration::registerCacheInvalidation();
