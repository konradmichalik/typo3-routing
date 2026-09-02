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

namespace KonradMichalik\Typo3Routing\Tests\Unit\DependencyInjection;

use KonradMichalik\Typo3Routing\Attribute\RateLimit;
use KonradMichalik\Typo3Routing\DependencyInjection\{CollectedRoutes, RateLimitResolver};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\RateLimitedController;
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * RateLimitResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RateLimitResolver::class)]
final class RateLimitResolverTest extends TestCase
{
    #[Test]
    public function resolvesTheClassLevelRateLimitAttribute(): void
    {
        $resolver = new RateLimitResolver();

        $rateLimit = $resolver->resolveClass(new ReflectionClass(RateLimitedController::class));

        self::assertNotNull($rateLimit);
        self::assertSame(10, $rateLimit->limit);
    }

    #[Test]
    public function returnsNullWhenTheClassHasNoRateLimitAttribute(): void
    {
        $resolver = new RateLimitResolver();

        self::assertNull($resolver->resolveClass(new ReflectionClass(self::class)));
    }

    #[Test]
    public function methodLevelRateLimitWinsOverTheClassLevelOne(): void
    {
        $resolver = new RateLimitResolver();
        $reflection = new ReflectionClass(RateLimitedController::class);
        $classRateLimit = $resolver->resolveClass($reflection);

        $rateLimit = $resolver->resolveMethod($reflection->getMethod('methodLevel'), $classRateLimit);

        self::assertNotNull($rateLimit);
        self::assertSame(5, $rateLimit->limit);
        self::assertSame('fixed_window', $rateLimit->policy);
    }

    #[Test]
    public function fallsBackToTheClassLevelRateLimitWhenTheMethodHasNoneOfItsOwn(): void
    {
        $resolver = new RateLimitResolver();
        $reflection = new ReflectionClass(RateLimitedController::class);
        $classRateLimit = $resolver->resolveClass($reflection);

        $rateLimit = $resolver->resolveMethod($reflection->getMethod('classLevel'), $classRateLimit);

        self::assertSame($classRateLimit, $rateLimit);
    }

    #[Test]
    public function applyStoresTheResolvedRateLimitIntoTheCollectedRoutes(): void
    {
        $resolver = new RateLimitResolver();
        $collected = new CollectedRoutes();
        $rateLimit = new RateLimit(limit: 42, interval: '1 hour', policy: 'sliding_window', keyBy: 'user');

        $resolver->apply($rateLimit, 'route_name', 'service_id', 'action', $collected);

        self::assertSame(42, $collected->rateLimits['route_name']['limit']);
        self::assertSame('1 hour', $collected->rateLimits['route_name']['interval']);
        self::assertSame('sliding_window', $collected->rateLimits['route_name']['policy']);
        self::assertSame('user', $collected->rateLimits['route_name']['keyBy']);
    }

    #[Test]
    public function applyIsANoOpWhenNoRateLimitWasResolved(): void
    {
        $resolver = new RateLimitResolver();
        $collected = new CollectedRoutes();

        $resolver->apply(null, 'route_name', 'service_id', 'action', $collected);

        self::assertSame([], $collected->rateLimits);
    }

    #[Test]
    public function throwsOnUnsupportedPolicy(): void
    {
        $resolver = new RateLimitResolver();
        $rateLimit = new RateLimit(policy: 'token_bucket');

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000001);
        $this->expectExceptionMessageMatches('/Unsupported #\[RateLimit\] policy/');

        $resolver->apply($rateLimit, 'route_name', 'service_id', 'action', new CollectedRoutes());
    }

    #[Test]
    public function throwsOnUnsupportedKeyBy(): void
    {
        $resolver = new RateLimitResolver();
        $rateLimit = new RateLimit(keyBy: 'session');

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000024);
        $this->expectExceptionMessageMatches('/Unsupported #\[RateLimit\] keyBy/');

        $resolver->apply($rateLimit, 'route_name', 'service_id', 'action', new CollectedRoutes());
    }
}
