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

namespace KonradMichalik\Typo3Routing\Cache;

use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use Psr\Http\Message\ResponseInterface;

/**
 * CacheInvalidator.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class CacheInvalidator
{
    public function __construct(
        private RouteRegistry $registry,
        private ResponseCacheManager $cache,
    ) {}

    /**
     * Flushes the route's #[InvalidatesCache] tags once it answered with a 2xx or 3xx status: a write
     * answering POST-redirect-GET succeeded too. A failed write changed nothing, so its error response
     * must not empty the cache.
     */
    public function afterResponse(string $routeName, ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 400) {
            return;
        }

        $tags = $this->registry->getInvalidatedCacheTags($routeName);
        if ([] !== $tags) {
            $this->cache->flushByTags($tags);
        }
    }
}
