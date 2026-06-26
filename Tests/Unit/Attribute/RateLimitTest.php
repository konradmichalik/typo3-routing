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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Attribute;

use KonradMichalik\Typo3Routing\Attribute\RateLimit;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * RateLimitTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RateLimit::class)]
final class RateLimitTest extends TestCase
{
    #[Test]
    public function defaultsToSixtyPerMinuteSlidingWindow(): void
    {
        $rateLimit = new RateLimit();

        self::assertSame(60, $rateLimit->limit);
        self::assertSame('1 minute', $rateLimit->interval);
        self::assertSame('sliding_window', $rateLimit->policy);
    }

    #[Test]
    public function storesAllProvidedValues(): void
    {
        $rateLimit = new RateLimit(limit: 10, interval: '10 seconds', policy: 'fixed_window');

        self::assertSame(10, $rateLimit->limit);
        self::assertSame('10 seconds', $rateLimit->interval);
        self::assertSame('fixed_window', $rateLimit->policy);
    }
}
