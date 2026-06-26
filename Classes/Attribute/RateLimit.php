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

namespace KonradMichalik\Typo3Routing\Attribute;

use Attribute;

/**
 * RateLimit.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class RateLimit
{
    /**
     * @param int    $limit    Maximum number of requests allowed within the interval
     * @param string $interval Time window as a relative date string (e.g. '1 minute', '10 seconds')
     * @param string $policy   Limiter policy: 'sliding_window' (default) or 'fixed_window'
     */
    public function __construct(
        public int $limit = 60,
        public string $interval = '1 minute',
        public string $policy = 'sliding_window',
    ) {}
}
