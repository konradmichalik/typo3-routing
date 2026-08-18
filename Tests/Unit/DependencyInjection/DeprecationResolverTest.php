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

use DateTimeImmutable;
use DateTimeZone;
use KonradMichalik\Typo3Routing\Attribute\DeprecatedRoute;
use KonradMichalik\Typo3Routing\DependencyInjection\{CollectedRoutes, DeprecationResolver};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\DeprecatedRouteController;
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * DeprecationResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(DeprecationResolver::class)]
final class DeprecationResolverTest extends TestCase
{
    #[Test]
    public function resolvesTheClassLevelAttribute(): void
    {
        $resolver = new DeprecationResolver();

        $deprecation = $resolver->resolveClass(new ReflectionClass(DeprecatedRouteController::class));

        self::assertNotNull($deprecation);
        self::assertSame('2026-01-01', $deprecation->since);
    }

    #[Test]
    public function returnsNullWhenTheClassHasNoAttribute(): void
    {
        $resolver = new DeprecationResolver();

        self::assertNull($resolver->resolveClass(new ReflectionClass(self::class)));
    }

    #[Test]
    public function methodLevelAttributeWinsOverTheClassLevelOne(): void
    {
        $resolver = new DeprecationResolver();
        $reflection = new ReflectionClass(DeprecatedRouteController::class);
        $classDeprecation = $resolver->resolveClass($reflection);

        $deprecation = $resolver->resolveMethod($reflection->getMethod('minimal'), $classDeprecation);

        self::assertNotNull($deprecation);
        self::assertSame('2027-01-01', $deprecation->since);
    }

    #[Test]
    public function fallsBackToTheClassLevelAttributeWhenTheMethodHasNoneOfItsOwn(): void
    {
        $resolver = new DeprecationResolver();
        $reflection = new ReflectionClass(DeprecatedRouteController::class);
        $classDeprecation = $resolver->resolveClass($reflection);

        $deprecation = $resolver->resolveMethod($reflection->getMethod('items'), $classDeprecation);

        self::assertSame($classDeprecation, $deprecation);
    }

    #[Test]
    public function doesNothingWhenNoAttributeIsGiven(): void
    {
        $collected = new CollectedRoutes();

        (new DeprecationResolver())->apply(null, 'route', 'ctrl', 'method', $collected);

        self::assertSame([], $collected->deprecations);
    }

    #[Test]
    public function parsesSinceAndSunsetIntoUnixTimestamps(): void
    {
        $collected = new CollectedRoutes();
        $deprecation = new DeprecatedRoute(since: '2026-01-01', sunset: '2026-12-31', successor: 'v2', documentation: 'https://example.com/migrate');

        (new DeprecationResolver())->apply($deprecation, 'v1', 'ctrl', 'method', $collected);

        self::assertSame((new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')))->getTimestamp(), $collected->deprecations['v1']['since']);
        self::assertSame((new DateTimeImmutable('2026-12-31', new DateTimeZone('UTC')))->getTimestamp(), $collected->deprecations['v1']['sunset']);
        self::assertSame('v2', $collected->deprecations['v1']['successor']);
        self::assertSame('https://example.com/migrate', $collected->deprecations['v1']['documentation']);
    }

    #[Test]
    public function acceptsASinceOnlyDeprecationWithNullSunsetSuccessorAndDocumentation(): void
    {
        $collected = new CollectedRoutes();

        (new DeprecationResolver())->apply(new DeprecatedRoute(since: '2026-01-01'), 'v1', 'ctrl', 'method', $collected);

        self::assertNull($collected->deprecations['v1']['sunset']);
        self::assertNull($collected->deprecations['v1']['successor']);
        self::assertNull($collected->deprecations['v1']['documentation']);
    }

    #[Test]
    public function acceptsASunsetEqualToSince(): void
    {
        $collected = new CollectedRoutes();

        (new DeprecationResolver())->apply(new DeprecatedRoute(since: '2026-01-01', sunset: '2026-01-01'), 'v1', 'ctrl', 'method', $collected);

        self::assertSame($collected->deprecations['v1']['since'], $collected->deprecations['v1']['sunset']);
    }

    #[Test]
    public function rejectsASunsetEarlierThanSince(): void
    {
        $collected = new CollectedRoutes();

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000032);
        $this->expectExceptionMessageMatches('/^#\[DeprecatedRoute\] on "ctrl::method\(\)" \(route "v1"\) has a "sunset" earlier than "since"/');

        (new DeprecationResolver())->apply(new DeprecatedRoute(since: '2026-12-31', sunset: '2026-01-01'), 'v1', 'ctrl', 'method', $collected);
    }

    #[Test]
    public function rejectsAnUnparseableSince(): void
    {
        $collected = new CollectedRoutes();

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000033);
        $this->expectExceptionMessageMatches('/^#\[DeprecatedRoute\] on "ctrl::method\(\)" \(route "v1"\) has an unparseable "since" value: "not-a-date"\./');

        (new DeprecationResolver())->apply(new DeprecatedRoute(since: 'not-a-date'), 'v1', 'ctrl', 'method', $collected);
    }

    #[Test]
    public function rejectsAnUnparseableSunset(): void
    {
        $collected = new CollectedRoutes();

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000033);
        $this->expectExceptionMessageMatches('/"sunset" value: "also-not-a-date"/');

        (new DeprecationResolver())->apply(new DeprecatedRoute(since: '2026-01-01', sunset: 'also-not-a-date'), 'v1', 'ctrl', 'method', $collected);
    }

    #[Test]
    public function doesNothingWhenEverySuccessorExists(): void
    {
        $collected = new CollectedRoutes();
        $collected->routes = ['v2' => ['path' => '/api/v2', 'methods' => ['GET'], 'controller' => 'ctrl::v2', 'env' => null, 'requirements' => []]];
        $collected->deprecations = ['v1' => ['since' => 1, 'sunset' => null, 'successor' => 'v2', 'documentation' => null]];

        (new DeprecationResolver())->assertSuccessorsExist($collected);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function doesNothingWhenNoSuccessorWasDeclared(): void
    {
        $collected = new CollectedRoutes();
        $collected->deprecations = ['v1' => ['since' => 1, 'sunset' => null, 'successor' => null, 'documentation' => null]];

        (new DeprecationResolver())->assertSuccessorsExist($collected);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsASuccessorThatIsNotARegisteredRoute(): void
    {
        $collected = new CollectedRoutes();
        $collected->deprecations = ['v1' => ['since' => 1, 'sunset' => null, 'successor' => 'v2', 'documentation' => null]];

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000034);
        $this->expectExceptionMessageMatches('/^#\[DeprecatedRoute\] on route "v1" names "v2" as its successor, but no such route is registered\.$/');

        (new DeprecationResolver())->assertSuccessorsExist($collected);
    }
}
