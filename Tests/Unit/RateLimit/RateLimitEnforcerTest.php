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

namespace KonradMichalik\Typo3Routing\Tests\Unit\RateLimit;

use KonradMichalik\Typo3Routing\RateLimit\RateLimitEnforcer;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * RateLimitEnforcerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RateLimitEnforcer::class)]
final class RateLimitEnforcerTest extends TestCase
{
    /**
     * @var array{limit: int, interval: string, policy: string}
     */
    private const CONFIG = ['limit' => 1, 'interval' => '1 minute', 'policy' => 'sliding_window'];

    #[Test]
    public function acceptsTheFirstRequestWithinTheLimit(): void
    {
        $enforcer = new RateLimitEnforcer(new InMemoryStorage());

        $result = $enforcer->consume('route_a', self::CONFIG, '203.0.113.1');

        self::assertTrue($result->isAccepted());
    }

    #[Test]
    public function blocksTheRequestExceedingTheLimitAndReportsRetryAfter(): void
    {
        $enforcer = new RateLimitEnforcer(new InMemoryStorage());

        $enforcer->consume('route_a', self::CONFIG, '203.0.113.1');
        $blocked = $enforcer->consume('route_a', self::CONFIG, '203.0.113.1');

        self::assertFalse($blocked->isAccepted());
        self::assertGreaterThan(0, $blocked->getRetryAfter()->getTimestamp());
    }

    #[Test]
    public function keepsSeparateBudgetsPerClient(): void
    {
        $enforcer = new RateLimitEnforcer(new InMemoryStorage());

        $enforcer->consume('route_a', self::CONFIG, '203.0.113.1');
        $otherClient = $enforcer->consume('route_a', self::CONFIG, '203.0.113.9');

        self::assertTrue($otherClient->isAccepted());
    }

    #[Test]
    public function keepsSeparateBudgetsPerRoute(): void
    {
        $enforcer = new RateLimitEnforcer(new InMemoryStorage());

        $enforcer->consume('route_a', self::CONFIG, '203.0.113.1');
        $otherRoute = $enforcer->consume('route_b', self::CONFIG, '203.0.113.1');

        self::assertTrue($otherRoute->isAccepted());
    }

    #[Test]
    public function supportsTheFixedWindowPolicy(): void
    {
        $enforcer = new RateLimitEnforcer(new InMemoryStorage());
        $config = ['limit' => 1, 'interval' => '1 minute', 'policy' => 'fixed_window'];

        $enforcer->consume('route_a', $config, '203.0.113.1');
        $blocked = $enforcer->consume('route_a', $config, '203.0.113.1');

        self::assertFalse($blocked->isAccepted());
    }
}
