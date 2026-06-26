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

namespace KonradMichalik\Typo3Routing\RateLimit;

use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * CacheStorage.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class CacheStorage implements StorageInterface
{
    public const CACHE_IDENTIFIER = 'typo3_routing_ratelimit';

    public function __construct(
        private FrontendInterface $cache,
    ) {}

    public function save(LimiterStateInterface $limiterState): void
    {
        $this->cache->set(
            $this->key($limiterState->getId()),
            $limiterState,
            [],
            $limiterState->getExpirationTime(),
        );
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $value = $this->cache->get($this->key($limiterStateId));

        return $value instanceof LimiterStateInterface ? $value : null;
    }

    public function delete(string $limiterStateId): void
    {
        $this->cache->remove($this->key($limiterStateId));
    }

    private function key(string $limiterStateId): string
    {
        return hash('sha256', $limiterStateId);
    }
}
