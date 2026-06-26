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

use KonradMichalik\Typo3Routing\DependencyInjection\RouteCompilerPass;
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\{AbstractRouteController, DuplicateNameController, FixtureController, InvalidRateLimitPolicyController, PlainService};
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\{ContainerBuilder, Definition, Reference};

/**
 * RouteCompilerPassTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RouteCompilerPass::class)]
final class RouteCompilerPassTest extends TestCase
{
    #[Test]
    public function discoversRoutesFromMarkerControllers(): void
    {
        $routes = $this->discover($this->buildContainer(['fixture_controller' => FixtureController::class]));

        self::assertArrayHasKey('fixture_count', $routes);
        self::assertSame('/api/count', $routes['fixture_count']['path']);
        self::assertSame(['GET'], $routes['fixture_count']['methods']);
        self::assertSame('fixture_controller::count', $routes['fixture_count']['controller']);
        self::assertNull($routes['fixture_count']['env']);
        self::assertSame([], $routes['fixture_count']['requirements']);
    }

    #[Test]
    public function keepsAllHttpMethodsAndRepeatableRoutes(): void
    {
        $routes = $this->discover($this->buildContainer(['fixture_controller' => FixtureController::class]));

        self::assertSame(['GET', 'POST'], $routes['fixture_controller_results']['methods']);
        self::assertArrayHasKey('fixture_alias_a', $routes);
        self::assertArrayHasKey('fixture_alias_b', $routes);
    }

    #[Test]
    public function derivesRouteNameFromServiceIdAndMethodWhenNoneGiven(): void
    {
        $routes = $this->discover($this->buildContainer(['fixture_controller' => FixtureController::class]));

        self::assertArrayHasKey('fixture_controller_results', $routes);
        self::assertSame('fixture_controller::results', $routes['fixture_controller_results']['controller']);
    }

    #[Test]
    public function bindsEnvFromAttribute(): void
    {
        $routes = $this->discover($this->buildContainer(['fixture_controller' => FixtureController::class]));

        self::assertSame('Development', $routes['fixture_dev']['env']);
    }

    #[Test]
    public function ignoresServicesNotImplementingMarkerInterface(): void
    {
        $routes = $this->discover($this->buildContainer(['plain' => PlainService::class]));

        self::assertSame([], $routes);
    }

    #[Test]
    public function injectsAControllerServiceLocatorReference(): void
    {
        $container = $this->buildContainer(['fixture_controller' => FixtureController::class]);
        (new RouteCompilerPass())->process($container);

        $locator = $container->getDefinition(RouteRegistry::class)->getArgument('$controllerLocator');

        self::assertInstanceOf(Reference::class, $locator);
    }

    #[Test]
    public function throwsOnDuplicateRouteName(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000000);

        $this->discover($this->buildContainer(['dup' => DuplicateNameController::class]));
    }

    #[Test]
    public function doesNothingWhenRegistryDefinitionIsMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('ctrl', new Definition(FixtureController::class));

        (new RouteCompilerPass())->process($container);

        self::assertFalse($container->hasDefinition(RouteRegistry::class));
    }

    #[Test]
    public function ignoresAbstractServiceDefinitions(): void
    {
        $container = $this->buildContainer([]);
        $definition = new Definition(FixtureController::class);
        $definition->setAbstract(true);
        $container->setDefinition('abstract_definition', $definition);

        self::assertSame([], $this->discover($container));
    }

    #[Test]
    public function ignoresDefinitionsWithoutAClass(): void
    {
        $container = $this->buildContainer([]);
        $container->setDefinition('classless', new Definition());

        self::assertSame([], $this->discover($container));
    }

    #[Test]
    public function ignoresAbstractControllerClasses(): void
    {
        self::assertSame([], $this->discover($this->buildContainer(['abstract' => AbstractRouteController::class])));
    }

    #[Test]
    public function capturesCacheConfigForAnnotatedMethods(): void
    {
        $container = $this->buildContainer(['fixture_controller' => FixtureController::class]);
        (new RouteCompilerPass())->process($container);

        /** @var array<string, array{lifetime: int, tags: list<string>, ignoreParams: list<string>}> $cacheConfigs */
        $cacheConfigs = $container->getDefinition(RouteRegistry::class)->getArgument('$cacheConfigs');

        self::assertArrayHasKey('fixture_count', $cacheConfigs);
        self::assertSame(120, $cacheConfigs['fixture_count']['lifetime']);
        self::assertSame(['tx_fixture'], $cacheConfigs['fixture_count']['tags']);
        // Methods without #[Cache] get no entry.
        self::assertArrayNotHasKey('fixture_controller_results', $cacheConfigs);
    }

    #[Test]
    public function capturesRateLimitForAnnotatedMethods(): void
    {
        $container = $this->buildContainer(['fixture_controller' => FixtureController::class]);
        (new RouteCompilerPass())->process($container);

        /** @var array<string, array{limit: int, interval: string, policy: string}> $rateLimits */
        $rateLimits = $container->getDefinition(RouteRegistry::class)->getArgument('$rateLimits');

        self::assertArrayHasKey('fixture_limited', $rateLimits);
        self::assertSame(5, $rateLimits['fixture_limited']['limit']);
        self::assertSame('10 seconds', $rateLimits['fixture_limited']['interval']);
        self::assertSame('fixed_window', $rateLimits['fixture_limited']['policy']);
        // Methods without #[RateLimit] get no entry.
        self::assertArrayNotHasKey('fixture_count', $rateLimits);
    }

    #[Test]
    public function throwsOnUnsupportedRateLimitPolicy(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000001);

        $this->discover($this->buildContainer(['bogus' => InvalidRateLimitPolicyController::class]));
    }

    /**
     * @param array<string, class-string> $services
     */
    private function buildContainer(array $services): ContainerBuilder
    {
        $container = new ContainerBuilder();

        $registry = new Definition(RouteRegistry::class);
        $registry->setArgument('$routes', []);
        $container->setDefinition(RouteRegistry::class, $registry);

        foreach ($services as $id => $class) {
            $definition = new Definition($class);
            $definition->setPublic(false);
            $container->setDefinition($id, $definition);
        }

        return $container;
    }

    /**
     * @return array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}>
     */
    private function discover(ContainerBuilder $container): array
    {
        (new RouteCompilerPass())->process($container);

        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = $container->getDefinition(RouteRegistry::class)->getArgument('$routes');

        return $routes;
    }
}
