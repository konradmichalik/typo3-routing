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

namespace KonradMichalik\RoutingBenchmark\RateLimit;

use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * The bucket store a developer would write by hand for the plain rate-limit middleware: limiter
 * state in a TYPO3 cache, nothing else.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class PlainRateLimitStorage implements StorageInterface
{
    public function __construct(
        private FrontendInterface $cache,
    ) {}

    public function save(LimiterStateInterface $limiterState): void
    {
        $this->cache->set(hash('sha256', $limiterState->getId()), $limiterState, [], $limiterState->getExpirationTime());
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $value = $this->cache->get(hash('sha256', $limiterStateId));

        return $value instanceof LimiterStateInterface ? $value : null;
    }

    public function delete(string $limiterStateId): void
    {
        $this->cache->remove(hash('sha256', $limiterStateId));
    }
}
