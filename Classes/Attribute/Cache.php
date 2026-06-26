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
 * Cache.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Cache
{
    /**
     * @param int          $lifetime     Time to live in seconds (default: one day)
     * @param list<string> $tags         Cache tags for invalidation; a tag matching a table name is flushed when a record of that table changes (e.g. ['tx_news_domain_model_news'])
     * @param list<string> $ignoreParams Query parameters excluded from the cache key (e.g. an individual ['search'] parameter)
     */
    public function __construct(
        public int $lifetime = 86400,
        public array $tags = [],
        public array $ignoreParams = [],
    ) {}
}
