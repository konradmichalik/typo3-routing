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

use KonradMichalik\Typo3Routing\Attribute\Cors;
use KonradMichalik\Typo3Routing\DependencyInjection\{CollectedRoutes, CorsResolver};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\CorsController;
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * CorsResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(CorsResolver::class)]
final class CorsResolverTest extends TestCase
{
    #[Test]
    public function resolvesTheClassLevelCorsAttribute(): void
    {
        $resolver = new CorsResolver();

        $cors = $resolver->resolveClass(new ReflectionClass(CorsController::class));

        self::assertNotNull($cors);
        self::assertSame(['https://class.example.com'], $cors->allowedOrigins);
    }

    #[Test]
    public function returnsNullWhenTheClassHasNoCorsAttribute(): void
    {
        $resolver = new CorsResolver();

        self::assertNull($resolver->resolveClass(new ReflectionClass(self::class)));
    }

    #[Test]
    public function methodLevelCorsWinsOverTheClassLevelOne(): void
    {
        $resolver = new CorsResolver();
        $reflection = new ReflectionClass(CorsController::class);
        $classCors = $resolver->resolveClass($reflection);

        $cors = $resolver->resolveMethod($reflection->getMethod('methodLevel'), $classCors);

        self::assertNotNull($cors);
        self::assertSame(['https://method.example.com'], $cors->allowedOrigins);
    }

    #[Test]
    public function fallsBackToTheClassLevelCorsWhenTheMethodHasNoneOfItsOwn(): void
    {
        $resolver = new CorsResolver();
        $reflection = new ReflectionClass(CorsController::class);
        $classCors = $resolver->resolveClass($reflection);

        $cors = $resolver->resolveMethod($reflection->getMethod('classLevel'), $classCors);

        self::assertSame($classCors, $cors);
    }

    #[Test]
    public function applyStoresTheResolvedCorsIntoTheCollectedRoutes(): void
    {
        $resolver = new CorsResolver();
        $collected = new CollectedRoutes();
        $cors = new Cors(allowedOrigins: ['https://app.example.com'], allowCredentials: true, maxAge: 120);

        $resolver->apply($cors, 'route_name', 'service_id', 'action', $collected);

        self::assertSame(['https://app.example.com'], $collected->corsConfigs['route_name']['allowedOrigins']);
        self::assertTrue($collected->corsConfigs['route_name']['allowCredentials']);
        self::assertSame(120, $collected->corsConfigs['route_name']['maxAge']);
    }

    #[Test]
    public function applyIsANoOpWhenNoCorsWasResolved(): void
    {
        $resolver = new CorsResolver();
        $collected = new CollectedRoutes();

        $resolver->apply(null, 'route_name', 'service_id', 'action', $collected);

        self::assertSame([], $collected->corsConfigs);
    }

    #[Test]
    public function throwsOnWildcardOriginCombinedWithCredentials(): void
    {
        $resolver = new CorsResolver();
        $cors = new Cors(allowedOrigins: ['*'], allowCredentials: true);

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000025);
        $this->expectExceptionMessageMatches('/allowCredentials.*wildcard/');

        $resolver->apply($cors, 'route_name', 'service_id', 'action', new CollectedRoutes());
    }
}
