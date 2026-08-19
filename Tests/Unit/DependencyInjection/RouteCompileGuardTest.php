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

use KonradMichalik\Typo3Routing\DependencyInjection\RouteCompileGuard;
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * RouteCompileGuardTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RouteCompileGuard::class)]
final class RouteCompileGuardTest extends TestCase
{
    #[Test]
    public function doesNothingWhenEveryRouteCompiles(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = ['ok' => ['path' => '/api/ok', 'methods' => ['GET'], 'controller' => 'ctrl::ok', 'env' => null, 'requirements' => []]];

        (new RouteCompileGuard())->assertCompiles(RouteRegistry::buildCollection($routes), $routes);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function wrapsACompileFailureNamingTheRouteAndController(): void
    {
        // A variable referenced twice in the same path pattern makes Symfony's compiler reject the
        // route outright — a stand-in for any Route::compile() failure, not just a non-ASCII one.
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = ['broken' => ['path' => '/api/broken/{id}/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::broken', 'env' => null, 'requirements' => []]];

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000026);
        $this->expectExceptionMessageMatches('/^Route "broken" \(ctrl::broken\) failed to compile:/');

        (new RouteCompileGuard())->assertCompiles(RouteRegistry::buildCollection($routes), $routes);
    }

    #[Test]
    public function namesTheControllerAsUnknownWhenTheRouteIsMissingFromTheBakedArray(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = ['broken' => ['path' => '/api/broken/{id}/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::broken', 'env' => null, 'requirements' => []]];
        $collection = RouteRegistry::buildCollection($routes);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/^Route "broken" \(unknown controller\) failed to compile:/');

        // The baked routes array passed in does not know about this route at all.
        (new RouteCompileGuard())->assertCompiles($collection, []);
    }
}
