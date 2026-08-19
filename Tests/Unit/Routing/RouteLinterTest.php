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

use KonradMichalik\Typo3Routing\Routing\{RouteLinter, RouteRegistry};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * RouteLinterTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RouteLinter::class)]
final class RouteLinterTest extends TestCase
{
    #[Test]
    public function findsNoFindingsForACleanRouteSet(): void
    {
        $registry = $this->registry(['count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []]]);

        self::assertSame([], (new RouteLinter())->lint($registry));
    }

    #[Test]
    public function flagsAPathWithNoStaticPrefix(): void
    {
        $registry = $this->registry(['open' => ['path' => '/{slug}', 'methods' => ['GET'], 'controller' => 'ctrl::open', 'env' => null, 'requirements' => []]]);

        $findings = (new RouteLinter())->lint($registry);

        self::assertCount(1, $findings);
        self::assertSame('no-static-prefix', $findings[0]['check']);
        self::assertSame('open', $findings[0]['route']);
        self::assertSame('ctrl::open', $findings[0]['controller']);
        self::assertSame('warning', $findings[0]['severity']);
    }

    #[Test]
    public function doesNotFlagAPathWithAStaticPrefix(): void
    {
        $registry = $this->registry(['item' => ['path' => '/api/item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::item', 'env' => null, 'requirements' => ['id' => '\d+']]]);

        self::assertSame([], (new RouteLinter())->lint($registry));
    }

    #[Test]
    public function flagsTwoRoutesWithTheSamePathAndOverlappingMethodsAtEqualPriority(): void
    {
        $registry = $this->registry([
            'dup_a' => ['path' => '/api/dup', 'methods' => ['GET'], 'controller' => 'ctrl::a', 'env' => null, 'requirements' => []],
            'dup_b' => ['path' => '/api/dup', 'methods' => ['GET'], 'controller' => 'ctrl::b', 'env' => null, 'requirements' => []],
        ]);

        $findings = (new RouteLinter())->lint($registry);

        self::assertCount(1, $findings);
        self::assertSame('ambiguous-duplicate-path', $findings[0]['check']);
        // The later-registered route carries the finding, naming the earlier one in its message.
        self::assertSame('dup_b', $findings[0]['route']);
        self::assertStringContainsString('dup_a', $findings[0]['message']);
    }

    #[Test]
    public function doesNotFlagTheSamePathWithNonOverlappingMethods(): void
    {
        $registry = $this->registry([
            'get_only' => ['path' => '/api/split', 'methods' => ['GET'], 'controller' => 'ctrl::get', 'env' => null, 'requirements' => []],
            'post_only' => ['path' => '/api/split', 'methods' => ['POST'], 'controller' => 'ctrl::post', 'env' => null, 'requirements' => []],
        ]);

        self::assertSame([], (new RouteLinter())->lint($registry));
    }

    #[Test]
    public function flagsTheLowerPriorityRouteAsFullyShadowed(): void
    {
        $registry = $this->registry([
            'high' => ['path' => '/api/shadow', 'methods' => ['GET'], 'controller' => 'ctrl::high', 'env' => null, 'requirements' => [], 'priority' => 10],
            'low' => ['path' => '/api/shadow', 'methods' => ['GET'], 'controller' => 'ctrl::low', 'env' => null, 'requirements' => []],
        ]);

        $findings = (new RouteLinter())->lint($registry);

        self::assertCount(1, $findings);
        self::assertSame('shadowed-route', $findings[0]['check']);
        self::assertSame('low', $findings[0]['route']);
        self::assertSame('ctrl::low', $findings[0]['controller']);
        self::assertStringContainsString('high', $findings[0]['message']);
    }

    #[Test]
    public function treatsAnEmptyMethodsListAsOverlappingWithAnyOtherMethod(): void
    {
        $registry = $this->registry([
            'any_methods' => ['path' => '/api/any', 'methods' => [], 'controller' => 'ctrl::any', 'env' => null, 'requirements' => []],
            'get_only' => ['path' => '/api/any', 'methods' => ['GET'], 'controller' => 'ctrl::get', 'env' => null, 'requirements' => []],
        ]);

        $findings = (new RouteLinter())->lint($registry);

        self::assertCount(1, $findings);
        self::assertSame('ambiguous-duplicate-path', $findings[0]['check']);
    }

    #[Test]
    public function flagsALowerPriorityRouteAsShadowedWhenTheHigherOneAcceptsAnyMethod(): void
    {
        $registry = $this->registry([
            'high' => ['path' => '/api/shadow-any', 'methods' => [], 'controller' => 'ctrl::high', 'env' => null, 'requirements' => [], 'priority' => 10],
            'low' => ['path' => '/api/shadow-any', 'methods' => ['GET'], 'controller' => 'ctrl::low', 'env' => null, 'requirements' => []],
        ]);

        $findings = (new RouteLinter())->lint($registry);

        self::assertCount(1, $findings);
        self::assertSame('shadowed-route', $findings[0]['check']);
        self::assertSame('low', $findings[0]['route']);
    }

    #[Test]
    public function doesNotFlagAnAnyMethodLowerPriorityRouteAsFullyShadowedByASpecificMethodRoute(): void
    {
        // The higher-priority route only covers GET; the lower one accepts any method, so POST etc. remain reachable.
        $registry = $this->registry([
            'high' => ['path' => '/api/any-low', 'methods' => ['GET'], 'controller' => 'ctrl::high', 'env' => null, 'requirements' => [], 'priority' => 10],
            'low' => ['path' => '/api/any-low', 'methods' => [], 'controller' => 'ctrl::low', 'env' => null, 'requirements' => []],
        ]);

        self::assertSame([], (new RouteLinter())->lint($registry));
    }

    #[Test]
    public function doesNotFlagAPartiallyOverlappingLowerPriorityRouteAsShadowed(): void
    {
        // The higher-priority route only covers GET; the lower one still answers POST.
        $registry = $this->registry([
            'high' => ['path' => '/api/partial', 'methods' => ['GET'], 'controller' => 'ctrl::high', 'env' => null, 'requirements' => [], 'priority' => 10],
            'low' => ['path' => '/api/partial', 'methods' => ['GET', 'POST'], 'controller' => 'ctrl::low', 'env' => null, 'requirements' => []],
        ]);

        self::assertSame([], (new RouteLinter())->lint($registry));
    }

    #[Test]
    public function doesNotFlagTheSamePathWithDisjointPlaceholderRequirements(): void
    {
        $registry = $this->registry([
            'numeric' => ['path' => '/api/item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::numeric', 'env' => null, 'requirements' => ['id' => '\d+']],
            'alpha' => ['path' => '/api/item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::alpha', 'env' => null, 'requirements' => ['id' => '[a-z]+']],
        ]);

        self::assertSame([], (new RouteLinter())->lint($registry));
    }

    #[Test]
    public function doesNotFlagTheSamePathWithDistinctHosts(): void
    {
        $registry = $this->registry([
            'api' => ['path' => '/status', 'methods' => ['GET'], 'controller' => 'ctrl::api', 'env' => null, 'requirements' => [], 'host' => 'api.example.com'],
            'www' => ['path' => '/status', 'methods' => ['GET'], 'controller' => 'ctrl::www', 'env' => null, 'requirements' => [], 'host' => 'www.example.com'],
        ]);

        self::assertSame([], (new RouteLinter())->lint($registry));
    }

    #[Test]
    public function doesNotFlagTheSamePathWithDistinctSchemes(): void
    {
        $registry = $this->registry([
            'http' => ['path' => '/api/split', 'methods' => ['GET'], 'controller' => 'ctrl::http', 'env' => null, 'requirements' => [], 'schemes' => ['http']],
            'https' => ['path' => '/api/split', 'methods' => ['GET'], 'controller' => 'ctrl::https', 'env' => null, 'requirements' => [], 'schemes' => ['https']],
        ]);

        self::assertSame([], (new RouteLinter())->lint($registry));
    }

    #[Test]
    public function doesNotFlagALowerPriorityRouteAsShadowedWhenRequirementsAreDisjoint(): void
    {
        $registry = $this->registry([
            'high' => ['path' => '/api/item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::high', 'env' => null, 'requirements' => ['id' => '\d+'], 'priority' => 10],
            'low' => ['path' => '/api/item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::low', 'env' => null, 'requirements' => ['id' => '[a-z]+']],
        ]);

        self::assertSame([], (new RouteLinter())->lint($registry));
    }

    #[Test]
    public function flagsAnIntTypedPathArgumentWithoutADigitsRequirement(): void
    {
        $registry = $this->registry(
            ['typed' => ['path' => '/api/typed/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::typed', 'env' => null, 'requirements' => []]],
            ['typed' => [['name' => 'id', 'type' => 'int', 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null]]],
        );

        $findings = (new RouteLinter())->lint($registry);

        self::assertCount(1, $findings);
        self::assertSame('missing-digits-requirement', $findings[0]['check']);
        self::assertSame('typed', $findings[0]['route']);
        self::assertStringContainsString('$id', $findings[0]['message']);
    }

    #[Test]
    public function doesNotFlagAnIntTypedPathArgumentWithADigitsRequirement(): void
    {
        $registry = $this->registry(
            ['typed' => ['path' => '/api/typed/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::typed', 'env' => null, 'requirements' => ['id' => '\d+']]],
            ['typed' => [['name' => 'id', 'type' => 'int', 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null]]],
        );

        self::assertSame([], (new RouteLinter())->lint($registry));
    }

    #[Test]
    public function flagsAnIntTypedPathArgumentWithANonDigitsRequirement(): void
    {
        $registry = $this->registry(
            ['typed' => ['path' => '/api/typed/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::typed', 'env' => null, 'requirements' => ['id' => '[a-z]+']]],
            ['typed' => [['name' => 'id', 'type' => 'int', 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null]]],
        );

        $findings = (new RouteLinter())->lint($registry);

        self::assertCount(1, $findings);
        self::assertSame('missing-digits-requirement', $findings[0]['check']);
    }

    #[Test]
    public function doesNotFlagANonIntOrNonPathArgument(): void
    {
        $registry = $this->registry(
            ['search' => ['path' => '/api/search', 'methods' => ['GET'], 'controller' => 'ctrl::search', 'env' => null, 'requirements' => []]],
            ['search' => [
                ['name' => 'q', 'type' => 'int', 'source' => 'input', 'nullable' => false, 'hasDefault' => false, 'default' => null],
                ['name' => 'label', 'type' => 'string', 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null],
            ]],
        );

        self::assertSame([], (new RouteLinter())->lint($registry));
    }

    #[Test]
    public function flagsAnExclusivePrefixWithNoRouteInsideIt(): void
    {
        $registry = $this->registry(['count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []]]);

        $findings = (new RouteLinter())->lint($registry, '/mcp/');

        self::assertCount(1, $findings);
        self::assertSame('unused-exclusive-prefix', $findings[0]['check']);
        self::assertSame('info', $findings[0]['severity']);
        self::assertNull($findings[0]['route']);
        self::assertNull($findings[0]['controller']);
        self::assertStringContainsString('/mcp/', $findings[0]['message']);
    }

    #[Test]
    public function doesNotFlagAnExclusivePrefixWithARouteInsideIt(): void
    {
        $registry = $this->registry(['count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []]]);

        self::assertSame([], (new RouteLinter())->lint($registry, '/api/'));
    }

    #[Test]
    public function ignoresABlankExclusivePrefixConfiguration(): void
    {
        $registry = $this->registry(['count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []]]);

        self::assertSame([], (new RouteLinter())->lint($registry, ''));
    }

    /**
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int}> $routes
     * @param array<string, list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>>                        $arguments
     */
    private function registry(array $routes, array $arguments = []): RouteRegistry
    {
        return new RouteRegistry($routes, new ServiceLocator([]), arguments: $arguments);
    }
}
