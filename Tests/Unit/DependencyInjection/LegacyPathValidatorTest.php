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

use KonradMichalik\Typo3Routing\DependencyInjection\{CollectedRoutes, LegacyPathValidator};
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * LegacyPathValidatorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(LegacyPathValidator::class)]
final class LegacyPathValidatorTest extends TestCase
{
    #[Test]
    public function doesNothingWhenNoRouteDeclaresALegacyPath(): void
    {
        $collected = new CollectedRoutes();
        $collected->routes = ['v2' => ['path' => '/api/v2', 'methods' => ['GET'], 'controller' => 'ctrl::v2', 'env' => null, 'requirements' => []]];

        (new LegacyPathValidator())->assertNoCollisions($collected);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function doesNothingWhenLegacyPathsAreUniqueAndFree(): void
    {
        $collected = new CollectedRoutes();
        $collected->routes = [
            'v2' => ['path' => '/api/v2', 'methods' => ['GET'], 'controller' => 'ctrl::v2', 'env' => null, 'requirements' => [], 'legacyPaths' => ['/api/v1']],
        ];

        (new LegacyPathValidator())->assertNoCollisions($collected);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsALegacyPathCollidingWithADeclaredRoutePath(): void
    {
        $collected = new CollectedRoutes();
        $collected->routes = [
            'v1' => ['path' => '/api/v1', 'methods' => ['GET'], 'controller' => 'ctrl::v1', 'env' => null, 'requirements' => []],
            'v2' => ['path' => '/api/v2', 'methods' => ['GET'], 'controller' => 'ctrl::v2', 'env' => null, 'requirements' => [], 'legacyPaths' => ['/api/v1']],
        ];

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000035);
        $this->expectExceptionMessageMatches('/^Legacy path "\/api\/v1" on route "v2" collides with a declared #\[Route\] path\./');

        (new LegacyPathValidator())->assertNoCollisions($collected);
    }

    #[Test]
    public function rejectsALegacyPathClaimedByTwoRoutes(): void
    {
        $collected = new CollectedRoutes();
        $collected->routes = [
            'a' => ['path' => '/api/a', 'methods' => ['GET'], 'controller' => 'ctrl::a', 'env' => null, 'requirements' => [], 'legacyPaths' => ['/api/old']],
            'b' => ['path' => '/api/b', 'methods' => ['GET'], 'controller' => 'ctrl::b', 'env' => null, 'requirements' => [], 'legacyPaths' => ['/api/old']],
        ];

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000036);
        $this->expectExceptionMessageMatches('/^Legacy path "\/api\/old" is claimed by both route "a" and route "b"\./');

        (new LegacyPathValidator())->assertNoCollisions($collected);
    }

    #[Test]
    public function rejectsALegacyPathMatchingADeclaredPlaceholderRoute(): void
    {
        $collected = new CollectedRoutes();
        $collected->routes = [
            'item' => ['path' => '/api/item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::item', 'env' => null, 'requirements' => ['id' => '\d+']],
            'renamed' => ['path' => '/api/renamed', 'methods' => ['GET'], 'controller' => 'ctrl::renamed', 'env' => null, 'requirements' => [], 'legacyPaths' => ['/api/item/42']],
        ];

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000035);
        $this->expectExceptionMessageMatches('/^Legacy path "\/api\/item\/42" on route "renamed" overlaps with a declared #\[Route\] path/');

        (new LegacyPathValidator())->assertNoCollisions($collected);
    }

    #[Test]
    public function rejectsALegacyPathThatIsOnlyATrailingSlashVariantOfADeclaredRoute(): void
    {
        $collected = new CollectedRoutes();
        $collected->routes = [
            'products' => ['path' => '/api/products', 'methods' => ['GET'], 'controller' => 'ctrl::products', 'env' => null, 'requirements' => []],
            'renamed' => ['path' => '/api/renamed', 'methods' => ['GET'], 'controller' => 'ctrl::renamed', 'env' => null, 'requirements' => [], 'legacyPaths' => ['/api/products/']],
        ];

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000035);

        (new LegacyPathValidator())->assertNoCollisions($collected);
    }

    #[Test]
    public function rejectsALegacyPathCollidingWithACaseInsensitiveDeclaredRouteInAnotherCase(): void
    {
        $collected = new CollectedRoutes();
        $collected->routes = [
            'products' => ['path' => '/api/products', 'methods' => ['GET'], 'controller' => 'ctrl::products', 'env' => null, 'requirements' => [], 'caseInsensitive' => true],
            'renamed' => ['path' => '/api/renamed', 'methods' => ['GET'], 'controller' => 'ctrl::renamed', 'env' => null, 'requirements' => [], 'legacyPaths' => ['/API/PRODUCTS']],
        ];

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000035);

        (new LegacyPathValidator())->assertNoCollisions($collected);
    }

    #[Test]
    public function rejectsALegacyPathMatchingADeclaredPlaceholderRouteUnderADifferentMethodToo(): void
    {
        // Distinct from the declared path string, so this only reaches the matcher-based overlap
        // check (not the exact-string collision check), and the declared route is POST-only while
        // the matcher's default request context method is GET — the path itself still matches, so
        // the matcher reports a method mismatch rather than a plain miss, which is still an overlap.
        $collected = new CollectedRoutes();
        $collected->routes = [
            'item' => ['path' => '/api/item/{id}', 'methods' => ['POST'], 'controller' => 'ctrl::item', 'env' => null, 'requirements' => ['id' => '\d+']],
            'renamed' => ['path' => '/api/renamed', 'methods' => ['GET'], 'controller' => 'ctrl::renamed', 'env' => null, 'requirements' => [], 'legacyPaths' => ['/api/item/42']],
        ];

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000035);

        (new LegacyPathValidator())->assertNoCollisions($collected);
    }

    #[Test]
    public function allowsALegacyPathThatDoesNotOverlapAnyDeclaredRouteShape(): void
    {
        $collected = new CollectedRoutes();
        $collected->routes = [
            'item' => ['path' => '/api/item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::item', 'env' => null, 'requirements' => ['id' => '\d+']],
            'loose' => ['path' => '/api/loose', 'methods' => ['GET'], 'controller' => 'ctrl::loose', 'env' => null, 'requirements' => [], 'caseInsensitive' => true],
            'renamed' => ['path' => '/api/renamed', 'methods' => ['GET'], 'controller' => 'ctrl::renamed', 'env' => null, 'requirements' => [], 'legacyPaths' => ['/api/old-name']],
        ];

        (new LegacyPathValidator())->assertNoCollisions($collected);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function doesNothingWhenLegacyAndDeclaredPlaceholdersMatch(): void
    {
        $collected = new CollectedRoutes();
        $collected->routes = [
            'show' => ['path' => '/api/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::show', 'env' => null, 'requirements' => [], 'legacyPaths' => ['/legacy/{id}']],
        ];

        (new LegacyPathValidator())->assertNoCollisions($collected);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function rejectsALegacyPathWithAnIncompatiblePlaceholderName(): void
    {
        $collected = new CollectedRoutes();
        $collected->routes = [
            'show' => ['path' => '/api/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::show', 'env' => null, 'requirements' => [], 'legacyPaths' => ['/legacy/{oldId}']],
        ];

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000037);
        $this->expectExceptionMessageMatches('/^Legacy path "\/legacy\/\{oldId\}" on route "show" declares placeholders \{oldId\}, but the route\'s path "\/api\/\{id\}" declares \{id\}\./');

        (new LegacyPathValidator())->assertNoCollisions($collected);
    }
}
