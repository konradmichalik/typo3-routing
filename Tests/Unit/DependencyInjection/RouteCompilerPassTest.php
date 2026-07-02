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
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\{AbstractRouteController, AuthenticatedController, CachedAuthenticatedController, DoubleClassRouteController, DuplicateNameController, FixtureController, GetOnlyRequestTokenController, InvalidAuthenticatorController, InvalidRateLimitPolicyController, OrphanedModifierController, PlainService, PrefixedController, TypedArgumentController, UnsupportedArgumentController};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Authentication\{DenyAuthenticator, PassAuthenticator};
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\{ContainerBuilder, Definition, Reference};

use function restore_error_handler;
use function set_error_handler;

use const E_USER_WARNING;

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
    public function appliesClassLevelRoutePrefixToPathNameEnvAndRequirements(): void
    {
        $routes = $this->discover($this->buildContainer(['prefixed' => PrefixedController::class]));

        // Class path + name prefix; class env and requirements inherited by the method.
        self::assertArrayHasKey('v1_items_show', $routes);
        self::assertSame('/api/v1/items/{id}', $routes['v1_items_show']['path']);
        self::assertSame('Development', $routes['v1_items_show']['env']);
        self::assertSame(['id' => '\d+'], $routes['v1_items_show']['requirements']);
    }

    #[Test]
    public function methodRouteOverridesClassLevelEnvAndRequirementsPerKey(): void
    {
        $routes = $this->discover($this->buildContainer(['prefixed' => PrefixedController::class]));

        // Name prefix + auto-derived method name; method env and requirement win.
        self::assertArrayHasKey('v1_prefixed_ping', $routes);
        self::assertSame('/api/v1/ping', $routes['v1_prefixed_ping']['path']);
        self::assertSame('Production', $routes['v1_prefixed_ping']['env']);
        self::assertSame(['id' => '[a-z]+'], $routes['v1_prefixed_ping']['requirements']);
    }

    #[Test]
    public function throwsOnMultipleClassLevelRoutePrefixes(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000014);

        $this->discover($this->buildContainer(['double' => DoubleClassRouteController::class]));
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

    #[Test]
    public function throwsWhenModifierAttributesAreUsedWithoutARoute(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000013);
        $this->expectExceptionMessageMatches('/#\[Cache\], #\[RateLimit\].*without a #\[Route\]/');

        $this->discover($this->buildContainer(['orphaned' => OrphanedModifierController::class]));
    }

    #[Test]
    public function bakesArgumentSpecsWithSourceAndType(): void
    {
        $container = $this->buildContainer(['typed' => TypedArgumentController::class]);
        (new RouteCompilerPass())->process($container);

        /** @var array<string, list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>> $arguments */
        $arguments = $container->getDefinition(RouteRegistry::class)->getArgument('$arguments');
        $specs = $arguments['typed_mixed'];

        // Path placeholder → 'path', typed int.
        self::assertSame(['name' => 'id', 'type' => 'int', 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null], $specs[0]);
        // Not a placeholder → 'input'.
        self::assertSame('q', $specs[1]['name']);
        self::assertSame('string', $specs[1]['type']);
        self::assertSame('input', $specs[1]['source']);
        // Optional bool with default.
        self::assertSame('bool', $specs[2]['type']);
        self::assertTrue($specs[2]['hasDefault']);
        self::assertFalse($specs[2]['default']);
        // PSR-7 request interface → 'request'.
        self::assertSame('request', $specs[3]['source']);
        self::assertNull($specs[3]['type']);
    }

    #[Test]
    public function throwsOnUnsupportedObjectParameterType(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000004);

        $this->discover($this->buildContainer(['unsupported' => UnsupportedArgumentController::class]));
    }

    #[Test]
    public function capturesOrCombinedAuthenticatorsWithOptions(): void
    {
        $container = $this->buildContainer([
            'auth_controller' => AuthenticatedController::class,
            PassAuthenticator::class => PassAuthenticator::class,
            DenyAuthenticator::class => DenyAuthenticator::class,
        ]);
        (new RouteCompilerPass())->process($container);

        /** @var array<string, list<array{service: string, options: array<string, mixed>}>> $authenticators */
        $authenticators = $container->getDefinition(RouteRegistry::class)->getArgument('$authenticators');

        self::assertSame([
            ['service' => PassAuthenticator::class, 'options' => []],
            ['service' => DenyAuthenticator::class, 'options' => ['role' => 'admin']],
        ], $authenticators['fixture_secure']);
    }

    #[Test]
    public function injectsAnAuthenticatorServiceLocatorReference(): void
    {
        $container = $this->buildContainer([
            'auth_controller' => AuthenticatedController::class,
            PassAuthenticator::class => PassAuthenticator::class,
            DenyAuthenticator::class => DenyAuthenticator::class,
        ]);
        (new RouteCompilerPass())->process($container);

        $locator = $container->getDefinition(RouteRegistry::class)->getArgument('$authenticatorLocator');

        self::assertInstanceOf(Reference::class, $locator);
    }

    #[Test]
    public function throwsWhenAnAuthenticatorDoesNotImplementTheContract(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000010);

        $this->discover($this->buildContainer(['broken' => InvalidAuthenticatorController::class]));
    }

    #[Test]
    public function throwsWhenAnAuthenticatorIsNotARegisteredService(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000011);

        // The controller references PassAuthenticator, but it is not registered as a service.
        $this->discover($this->buildContainer(['auth_controller' => AuthenticatedController::class]));
    }

    #[Test]
    public function capturesExplicitAndDerivedRequestTokenScopes(): void
    {
        $container = $this->buildContainer([
            'auth_controller' => AuthenticatedController::class,
            PassAuthenticator::class => PassAuthenticator::class,
            DenyAuthenticator::class => DenyAuthenticator::class,
        ]);
        (new RouteCompilerPass())->process($container);

        /** @var array<string, string> $scopes */
        $scopes = $container->getDefinition(RouteRegistry::class)->getArgument('$requestTokenScopes');

        self::assertSame('routing/account-update', $scopes['fixture_account_update']);
        // Derived from the route name when no scope is given.
        self::assertSame('routing/fixture_token_default', $scopes['fixture_token_default']);
    }

    #[Test]
    public function throwsWhenRequestTokenIsRequiredOnAGetOnlyRoute(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000012);

        $this->discover($this->buildContainer(['get_token' => GetOnlyRequestTokenController::class]));
    }

    #[Test]
    public function warnsWhenCacheIsCombinedWithAuthentication(): void
    {
        $container = $this->buildContainer([
            'cached_auth' => CachedAuthenticatedController::class,
            PassAuthenticator::class => PassAuthenticator::class,
        ]);

        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        }, E_USER_WARNING);

        try {
            (new RouteCompilerPass())->process($container);
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $warnings);
        self::assertStringContainsString('combines #[Cache] with #[Authenticate]', $warnings[0]);
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
