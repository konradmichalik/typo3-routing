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

use KonradMichalik\Typo3Routing\RateLimit\{RateLimitCheck, RateLimitEnforcer};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * RateLimitCheckTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RateLimitCheck::class)]
final class RateLimitCheckTest extends TestCase
{
    /**
     * @var array{limit: int, interval: string, policy: string, keyBy: string}
     */
    private const CONFIG = ['limit' => 1, 'interval' => '1 minute', 'policy' => 'sliding_window', 'keyBy' => 'ip'];

    #[Test]
    public function anAcceptedRequestIsNotBlockedAndCarriesItsQuotaHeaders(): void
    {
        $check = new RateLimitCheck(new RateLimitEnforcer(new InMemoryStorage()));

        $result = $check->evaluate('route_a', self::CONFIG, '203.0.113.1');

        self::assertNull($result['blocked']);
        self::assertSame('1', $result['headers']['X-RateLimit-Limit']);
        self::assertSame('0', $result['headers']['X-RateLimit-Remaining']);
        self::assertGreaterThan(0, (int) $result['headers']['X-RateLimit-Reset']);
    }

    #[Test]
    public function aBlockedRequestReturnsA429WithRetryAfterAndStillCarriesQuotaHeaders(): void
    {
        $check = new RateLimitCheck(new RateLimitEnforcer(new InMemoryStorage()));

        $check->evaluate('route_a', self::CONFIG, '203.0.113.1');
        $result = $check->evaluate('route_a', self::CONFIG, '203.0.113.1');

        self::assertNotNull($result['blocked']);
        self::assertSame(429, $result['blocked']->getStatusCode());
        self::assertNotSame('', $result['blocked']->getHeaderLine('Retry-After'));
        self::assertSame('1', $result['headers']['X-RateLimit-Limit']);
        self::assertSame('0', $result['headers']['X-RateLimit-Remaining']);
    }
}
