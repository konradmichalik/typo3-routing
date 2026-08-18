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

use KonradMichalik\Typo3Routing\DependencyInjection\{CollectedRoutes, RouteAliasCollector};
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * RouteAliasCollectorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RouteAliasCollector::class)]
final class RouteAliasCollectorTest extends TestCase
{
    #[Test]
    public function collectsAnAliasPointingToItsRoute(): void
    {
        $collected = new CollectedRoutes();

        (new RouteAliasCollector())->apply(['legacy_count'], '', 'fixture_count', 'ctrl', 'count', $collected);

        self::assertSame(['legacy_count' => 'fixture_count'], $collected->aliases);
    }

    #[Test]
    public function prefixesTheAliasTheSameWayTheRouteNameIsPrefixed(): void
    {
        $collected = new CollectedRoutes();

        (new RouteAliasCollector())->apply(['legacy'], 'v1_', 'v1_items_show', 'ctrl', 'show', $collected);

        self::assertSame(['v1_legacy' => 'v1_items_show'], $collected->aliases);
    }

    #[Test]
    public function collectsMultipleAliasesForTheSameRoute(): void
    {
        $collected = new CollectedRoutes();

        (new RouteAliasCollector())->apply(['legacy_a', 'legacy_b'], '', 'fixture_count', 'ctrl', 'count', $collected);

        self::assertSame(['legacy_a' => 'fixture_count', 'legacy_b' => 'fixture_count'], $collected->aliases);
    }

    #[Test]
    public function allowsTheSameRouteToRedeclareItsOwnAlias(): void
    {
        // The same route pointing to the same alias twice is not a collision with itself.
        $collected = new CollectedRoutes();
        $collector = new RouteAliasCollector();

        $collector->apply(['legacy'], '', 'fixture_count', 'ctrl', 'count', $collected);
        $collector->apply(['legacy'], '', 'fixture_count', 'ctrl', 'count', $collected);

        self::assertSame(['legacy' => 'fixture_count'], $collected->aliases);
    }

    #[Test]
    public function rejectsTheSameAliasDeclaredByTwoDifferentRoutes(): void
    {
        $collected = new CollectedRoutes();
        $collector = new RouteAliasCollector();
        $collector->apply(['legacy'], '', 'fixture_count', 'ctrl', 'count', $collected);

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000033);
        $this->expectExceptionMessageMatches('/^Route alias "legacy" is declared by both "fixture_count" and "fixture_other" \(ctrl::other\(\)\)\./');

        $collector->apply(['legacy'], '', 'fixture_other', 'ctrl', 'other', $collected);
    }

    #[Test]
    public function doesNothingWhenNoAliasCollidesWithARoute(): void
    {
        $collected = new CollectedRoutes();
        $collected->routes = ['fixture_count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []]];
        $collected->aliases = ['legacy_count' => 'fixture_count'];

        (new RouteAliasCollector())->assertNoCollisionWithRoutes($collected);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsAnAliasCollidingWithAnExistingRouteName(): void
    {
        $collected = new CollectedRoutes();
        $collected->routes = [
            'fixture_count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'legacy_count' => ['path' => '/api/legacy-count', 'methods' => ['GET'], 'controller' => 'ctrl::legacyCount', 'env' => null, 'requirements' => []],
        ];
        $collected->aliases = ['legacy_count' => 'fixture_count'];

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000032);
        $this->expectExceptionMessageMatches('/^Route alias "legacy_count" declared by "fixture_count" collides with an existing route of the same name\.$/');

        (new RouteAliasCollector())->assertNoCollisionWithRoutes($collected);
    }

    #[Test]
    public function rejectsARouteAliasingItsOwnName(): void
    {
        $collected = new CollectedRoutes();
        $collected->routes = ['fixture_count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []]];
        $collected->aliases = ['fixture_count' => 'fixture_count'];

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000032);

        (new RouteAliasCollector())->assertNoCollisionWithRoutes($collected);
    }
}
