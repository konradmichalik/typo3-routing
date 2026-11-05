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
 * InvalidatesCache.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class InvalidatesCache
{
    /**
     * @param list<string> $tags #[Cache] tags flushed from the response cache once the route answered with a 2xx or 3xx status (e.g. ['tx_news_domain_model_news'])
     */
    public function __construct(
        public array $tags,
    ) {}
}
