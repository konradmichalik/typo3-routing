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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Routing;

use KonradMichalik\Typo3Routing\Routing\RouteConstraintOverlap;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * RouteConstraintOverlapTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RouteConstraintOverlap::class)]
final class RouteConstraintOverlapTest extends TestCase
{
    #[Test]
    public function overlapsWhenNeitherRouteDeclaresAConstraint(): void
    {
        $a = ['path' => '/api/item/{id}', 'requirements' => []];
        $b = ['path' => '/api/item/{id}', 'requirements' => []];

        self::assertTrue((new RouteConstraintOverlap())->overlaps($a, $b));
    }

    #[Test]
    public function doesNotOverlapWhenPlaceholderRequirementsAreDisjoint(): void
    {
        $a = ['path' => '/api/item/{id}', 'requirements' => ['id' => '\d+']];
        $b = ['path' => '/api/item/{id}', 'requirements' => ['id' => '[a-z]+']];

        self::assertFalse((new RouteConstraintOverlap())->overlaps($a, $b));
    }

    #[Test]
    public function overlapsWhenOnlyOneSideConstrainsThePlaceholder(): void
    {
        $a = ['path' => '/api/item/{id}', 'requirements' => ['id' => '\d+']];
        $b = ['path' => '/api/item/{id}', 'requirements' => []];

        self::assertTrue((new RouteConstraintOverlap())->overlaps($a, $b));
    }

    #[Test]
    public function doesNotOverlapWhenHostsAreDistinctLiterals(): void
    {
        $a = ['path' => '/status', 'requirements' => [], 'host' => 'api.example.com'];
        $b = ['path' => '/status', 'requirements' => [], 'host' => 'www.example.com'];

        self::assertFalse((new RouteConstraintOverlap())->overlaps($a, $b));
    }

    #[Test]
    public function overlapsWhenAHostIsAPlaceholderPattern(): void
    {
        $a = ['path' => '/status', 'requirements' => [], 'host' => '{subdomain}.example.com'];
        $b = ['path' => '/status', 'requirements' => [], 'host' => 'www.example.com'];

        self::assertTrue((new RouteConstraintOverlap())->overlaps($a, $b));
    }

    #[Test]
    public function overlapsWhenOnlyOneSideDeclaresAHost(): void
    {
        $a = ['path' => '/status', 'requirements' => [], 'host' => 'api.example.com'];
        $b = ['path' => '/status', 'requirements' => []];

        self::assertTrue((new RouteConstraintOverlap())->overlaps($a, $b));
    }

    #[Test]
    public function doesNotOverlapWhenSchemesAreDisjoint(): void
    {
        $a = ['path' => '/api/split', 'requirements' => [], 'schemes' => ['http']];
        $b = ['path' => '/api/split', 'requirements' => [], 'schemes' => ['https']];

        self::assertFalse((new RouteConstraintOverlap())->overlaps($a, $b));
    }

    #[Test]
    public function overlapsWhenOnlyOneSideRestrictsTheScheme(): void
    {
        $a = ['path' => '/api/split', 'requirements' => [], 'schemes' => ['https']];
        $b = ['path' => '/api/split', 'requirements' => []];

        self::assertTrue((new RouteConstraintOverlap())->overlaps($a, $b));
    }
}
