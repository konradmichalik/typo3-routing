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

use KonradMichalik\Typo3Routing\Attribute\Route;
use KonradMichalik\Typo3Routing\DependencyInjection\ClassExclusiveResolver;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\{ExclusiveController, MethodLevelExclusiveController, PlaceholderExclusiveController};
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ClassExclusiveResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(ClassExclusiveResolver::class)]
final class ClassExclusiveResolverTest extends TestCase
{
    #[Test]
    public function returnsNullWhenThereIsNoClassLevelRoute(): void
    {
        $resolver = new ClassExclusiveResolver();

        self::assertNull($resolver->resolvePrefix(null, 'service_id'));
    }

    #[Test]
    public function returnsNullWhenTheClassRouteDidNotOptIntoExclusive(): void
    {
        $resolver = new ClassExclusiveResolver();
        $route = new Route(path: '/api/regular');

        self::assertNull($resolver->resolvePrefix($route, 'service_id'));
    }

    #[Test]
    public function resolvesTheStaticPrefixOfAnOptedInClass(): void
    {
        $resolver = new ClassExclusiveResolver();
        $reflection = new ReflectionClass(ExclusiveController::class);
        $classRoute = $reflection->getAttributes(Route::class)[0]->newInstance();

        $prefix = $resolver->resolvePrefix($classRoute, 'service_id');

        self::assertSame('/api/exclusive', $prefix);
    }

    #[Test]
    public function throwsWhenTheOptedInClassPathHasNoStaticPrefix(): void
    {
        $resolver = new ClassExclusiveResolver();
        $reflection = new ReflectionClass(PlaceholderExclusiveController::class);
        $classRoute = $reflection->getAttributes(Route::class)[0]->newInstance();

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000033);
        $this->expectExceptionMessageMatches('/claim every unmatched path site-wide/');

        $resolver->resolvePrefix($classRoute, 'service_id');
    }

    #[Test]
    public function assertNotOnMethodIsANoOpWhenTheMethodRouteIsNotExclusive(): void
    {
        $this->expectNotToPerformAssertions();

        $resolver = new ClassExclusiveResolver();
        $reflection = new ReflectionClass(ExclusiveController::class);
        $method = $reflection->getMethod('known');
        $route = $method->getAttributes(Route::class)[0]->newInstance();

        $resolver->assertNotOnMethod($route, $method, 'service_id');
    }

    #[Test]
    public function throwsWhenAMethodRouteOptsIntoExclusive(): void
    {
        $resolver = new ClassExclusiveResolver();
        $reflection = new ReflectionClass(MethodLevelExclusiveController::class);
        $method = $reflection->getMethod('ping');
        $route = $method->getAttributes(Route::class)[0]->newInstance();

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000032);
        $this->expectExceptionMessageMatches('/has no effect on a method route/');

        $resolver->assertNotOnMethod($route, $method, 'service_id');
    }
}
