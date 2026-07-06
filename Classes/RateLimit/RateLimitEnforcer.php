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

use Symfony\Component\RateLimiter\{RateLimit, RateLimiterFactory};
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/**
 * RateLimitEnforcer.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RateLimitEnforcer
{
    public function __construct(
        private StorageInterface $storage,
    ) {}

    /**
     * Counters are best-effort under concurrency: the factory is created without a lock factory,
     * so parallel requests can read the same bucket state and briefly exceed the configured limit.
     * That keeps the hot path free of cross-process locking; treat the limit as a throttle, not as
     * an exact quota or a hard security boundary.
     *
     * @param array{limit: int, interval: string, policy: string, keyBy: string} $config
     */
    public function consume(string $routeName, array $config, string $clientId): RateLimit
    {
        $factory = new RateLimiterFactory([
            'id' => $routeName,
            'policy' => $config['policy'],
            'limit' => $config['limit'],
            'interval' => $config['interval'],
        ], $this->storage);

        return $factory->create($clientId)->consume();
    }
}
