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
use KonradMichalik\Typo3Routing\Tests\Support\Broken\BrokenParentService;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\{AbstractRouteController, AuthenticatedController, BrokenAuthenticatorController, CachedAuthenticatedController, CaseInsensitiveController, ClassBaseParamController, ConflictingDefaultController, ConflictingParamController, CorsController, DeleteRequestTokenController, DoubleClassRouteController, DuplicateNameController, FixtureController, GetOnlyRequestTokenController, InvalidAuthenticatorController, InvalidCorsCredentialsController, InvalidRateLimitKeyController, InvalidRateLimitPolicyController, OrphanedCorsController, OrphanedModifierController, ParamContributionController, PlainService, PrefixedController, ReservedDefaultKeyController, TypedArgumentController, UnsupportedArgumentController};
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
    public function bakesRoutePriority(): void
    {
        $routes = $this->discover($this->buildContainer(['fixture_controller' => FixtureController::class]));

        self::assertSame(10, $routes['fixture_preferred']['priority'] ?? null);
        // Routes without an explicit priority default to 0.
        self::assertSame(0, $routes['fixture_count']['priority'] ?? null);
    }

    #[Test]
    public function bakesRouteSchemesAndHost(): void
    {
        $routes = $this->discover($this->buildContainer(['fixture_controller' => FixtureController::class]));

        self::assertSame(['https'], $routes['fixture_secure_only']['schemes'] ?? null);
        self::assertSame('api.example.com', $routes['fixture_secure_only']['host'] ?? null);
        // Routes without an explicit constraint default to any-scheme / any-host.
        self::assertSame([], $routes['fixture_count']['schemes'] ?? null);
        self::assertNull($routes['fixture_count']['host'] ?? null);
    }

    #[Test]
    public function appliesClassLevelRoutePrefixToPathNameEnvAndRequirements(): void
    {
        $routes = $this->discover($this->buildContainer(['prefixed' => PrefixedController::class]));

        // Class path + name prefix; class env, requirements and defaults inherited by the method.
        self::assertArrayHasKey('v1_items_show', $routes);
        self::assertSame('/api/v1/items/{id}', $routes['v1_items_show']['path']);
        self::assertSame('Development', $routes['v1_items_show']['env']);
        self::assertSame(['id' => '\d+'], $routes['v1_items_show']['requirements']);
        self::assertSame(['format' => 'html', 'page' => 1], $routes['v1_items_show']['defaults'] ?? null);
        // No own description: falls back to the class-level description.
        self::assertSame('Course catalogue endpoints.', $routes['v1_items_show']['description'] ?? null);
    }

    #[Test]
    public function inheritsTheCaseInsensitiveOptInFromTheClassLevelRoute(): void
    {
        $routes = $this->discover($this->buildContainer(['loose' => CaseInsensitiveController::class]));

        self::assertTrue($routes['loose_inherited']['caseInsensitive'] ?? null);
    }

    #[Test]
    public function aMethodCanOptOutOfAClassLevelCaseInsensitiveOptIn(): void
    {
        $routes = $this->discover($this->buildContainer(['loose' => CaseInsensitiveController::class]));

        self::assertFalse($routes['loose_strict']['caseInsensitive'] ?? null);
    }

    #[Test]
    public function routesWithoutTheOptInStayCaseSensitive(): void
    {
        $routes = $this->discover($this->buildContainer(['fixture_controller' => FixtureController::class]));

        self::assertFalse($routes['fixture_count']['caseInsensitive'] ?? null);
    }

    #[Test]
    public function bakesTheCaseInsensitivePrefixesIntoTheRegistry(): void
    {
        $container = $this->buildContainer(['loose' => CaseInsensitiveController::class]);
        (new RouteCompilerPass())->process($container);

        self::assertSame(['/api/loose/inherited'], $container->getDefinition(RouteRegistry::class)->getArgument('$caseInsensitivePrefixes'));
    }

    #[Test]
    public function hoistsParamRequirementAndPhpDefaultIntoTheRoute(): void
    {
        $routes = $this->discover($this->buildContainer(['param' => ParamContributionController::class]));

        self::assertSame(['page' => '\d+'], $routes['param_blog']['requirements']);
        self::assertSame(['page' => 1], $routes['param_blog']['defaults'] ?? null);
    }

    #[Test]
    public function keysAHoistedRequirementByTheParamWireName(): void
    {
        $routes = $this->discover($this->buildContainer(['param' => ParamContributionController::class]));

        self::assertSame(['q' => '\w+'], $routes['param_search']['requirements']);
    }

    #[Test]
    public function bakesParamDescriptionsIntoTheRegistry(): void
    {
        $container = $this->buildContainer(['param' => ParamContributionController::class]);
        (new RouteCompilerPass())->process($container);

        /** @var array<string, array<string, string>> $descriptions */
        $descriptions = $container->getDefinition(RouteRegistry::class)->getArgument('$paramDescriptions');

        self::assertSame(['page' => 'Page number, 1-based.'], $descriptions['param_blog']);
        // A route whose parameters carry no description contributes no entry at all.
        self::assertArrayNotHasKey('param_search', $descriptions);
    }

    #[Test]
    public function paramRequirementOverridesAClassLevelBase(): void
    {
        $routes = $this->discover($this->buildContainer(['classbase' => ClassBaseParamController::class]));

        self::assertSame(['id' => '[a-z]+'], $routes['v2_items_show']['requirements']);
    }

    #[Test]
    public function rejectsARequirementDeclaredOnBothRouteAndParam(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000029);

        $this->discover($this->buildContainer(['conflicting' => ConflictingParamController::class]));
    }

    #[Test]
    public function rejectsADefaultDeclaredOnBothRouteAndParam(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000030);

        $this->discover($this->buildContainer(['conflicting' => ConflictingDefaultController::class]));
    }

    #[Test]
    public function paramDefaultOverridesAClassLevelBase(): void
    {
        $routes = $this->discover($this->buildContainer(['classbase' => ClassBaseParamController::class]));

        // The class-level default 9 is a base only — the parameter's own default wins, without throwing.
        self::assertSame(['page' => 1], $routes['v2_blog']['defaults'] ?? null);
    }

    #[Test]
    public function methodRouteOverridesClassLevelEnvAndRequirementsPerKey(): void
    {
        $routes = $this->discover($this->buildContainer(['prefixed' => PrefixedController::class]));

        // Name prefix + auto-derived method name; method env, requirement and default win per key.
        self::assertArrayHasKey('v1_prefixed_ping', $routes);
        self::assertSame('/api/v1/ping', $routes['v1_prefixed_ping']['path']);
        self::assertSame('Production', $routes['v1_prefixed_ping']['env']);
        self::assertSame(['id' => '[a-z]+'], $routes['v1_prefixed_ping']['requirements']);
        // Method 'format' overrides the class default; class 'page' is still inherited.
        self::assertSame(['format' => 'json', 'page' => 1], $routes['v1_prefixed_ping']['defaults'] ?? null);
        // Method description overrides the class-level description.
        self::assertSame('Health check endpoint.', $routes['v1_prefixed_ping']['description'] ?? null);
    }

    #[Test]
    public function bakesRouteDefaults(): void
    {
        $routes = $this->discover($this->buildContainer(['fixture_controller' => FixtureController::class]));

        self::assertSame(['page' => 1], $routes['fixture_blog']['defaults'] ?? null);
        // Routes without explicit defaults get an empty array.
        self::assertSame([], $routes['fixture_count']['defaults'] ?? null);
    }

    #[Test]
    public function bakesRouteDescription(): void
    {
        $routes = $this->discover($this->buildContainer(['fixture_controller' => FixtureController::class]));

        self::assertSame('Only reachable over HTTPS on api.example.com.', $routes['fixture_secure_only']['description'] ?? null);
        // Routes without an explicit description default to null.
        self::assertNull($routes['fixture_count']['description'] ?? null);
    }

    #[Test]
    public function throwsOnReservedDefaultKey(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000022);

        $this->discover($this->buildContainer(['reserved' => ReservedDefaultKeyController::class]));
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
    public function ignoresServiceDefinitionsWhoseClassFailsToAutoload(): void
    {
        // BrokenParentService extends a missing class: an unrelated, broken third-party service must
        // not abort compilation of the whole container.
        self::assertSame([], $this->discover($this->buildContainer(['broken' => BrokenParentService::class])));
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
    public function bakesCompiledMatcherRoutes(): void
    {
        $container = $this->buildContainer(['fixture_controller' => FixtureController::class]);
        (new RouteCompilerPass())->process($container);

        /** @var array<mixed> $compiled */
        $compiled = $container->getDefinition(RouteRegistry::class)->getArgument('$compiledRoutes');

        // CompiledUrlMatcher format: [matchHost, staticRoutes, regexpList, dynamicRoutes, checkCondition].
        self::assertCount(5, $compiled);
        self::assertArrayHasKey('/api/count', (array) $compiled[1]);
    }

    #[Test]
    public function bakesTheStaticPathPrefixesFormingTheDispatcherGate(): void
    {
        $container = $this->buildContainer(['fixture_controller' => FixtureController::class]);
        (new RouteCompilerPass())->process($container);

        /** @var list<string> $prefixes */
        $prefixes = $container->getDefinition(RouteRegistry::class)->getArgument('$staticPrefixes');

        self::assertContains('/api/count', $prefixes);
        // A placeholder ends the static part, so the gate covers every /api/blog/… path.
        self::assertContains('/api/blog', $prefixes);
        // No route may widen the gate to everything here.
        self::assertNotContains('', $prefixes);
    }

    #[Test]
    public function bakesAnEmptyPrefixListWhenNoRouteControllerIsRegistered(): void
    {
        $container = $this->buildContainer([]);
        (new RouteCompilerPass())->process($container);

        self::assertSame([], $container->getDefinition(RouteRegistry::class)->getArgument('$staticPrefixes'));
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

        /** @var array<string, array{limit: int, interval: string, policy: string, keyBy: string}> $rateLimits */
        $rateLimits = $container->getDefinition(RouteRegistry::class)->getArgument('$rateLimits');

        self::assertArrayHasKey('fixture_limited', $rateLimits);
        self::assertSame(5, $rateLimits['fixture_limited']['limit']);
        self::assertSame('10 seconds', $rateLimits['fixture_limited']['interval']);
        self::assertSame('fixed_window', $rateLimits['fixture_limited']['policy']);
        // Defaults to IP keying when not specified.
        self::assertSame('ip', $rateLimits['fixture_limited']['keyBy']);
        // Methods without #[RateLimit] get no entry.
        self::assertArrayNotHasKey('fixture_count', $rateLimits);
    }

    #[Test]
    public function capturesCorsConfigForTheMethodsOwnAttribute(): void
    {
        $container = $this->buildContainer(['cors_controller' => CorsController::class]);
        (new RouteCompilerPass())->process($container);

        /** @var array<string, array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}> $corsConfigs */
        $corsConfigs = $container->getDefinition(RouteRegistry::class)->getArgument('$corsConfigs');

        self::assertSame(['https://method.example.com'], $corsConfigs['cors_method_level']['allowedOrigins']);
        self::assertTrue($corsConfigs['cors_method_level']['allowCredentials']);
        self::assertSame(600, $corsConfigs['cors_method_level']['maxAge']);
    }

    #[Test]
    public function fallsBackToTheClassLevelCorsForMethodsWithoutTheirOwn(): void
    {
        $container = $this->buildContainer(['cors_controller' => CorsController::class]);
        (new RouteCompilerPass())->process($container);

        /** @var array<string, array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}> $corsConfigs */
        $corsConfigs = $container->getDefinition(RouteRegistry::class)->getArgument('$corsConfigs');

        self::assertSame(['https://class.example.com'], $corsConfigs['cors_class_level']['allowedOrigins']);
        self::assertFalse($corsConfigs['cors_class_level']['allowCredentials']);
    }

    #[Test]
    public function omitsCorsConfigForRoutesWithoutTheAttribute(): void
    {
        $container = $this->buildContainer(['fixture_controller' => FixtureController::class]);
        (new RouteCompilerPass())->process($container);

        /** @var array<string, mixed> $corsConfigs */
        $corsConfigs = $container->getDefinition(RouteRegistry::class)->getArgument('$corsConfigs');

        self::assertArrayNotHasKey('fixture_count', $corsConfigs);
    }

    #[Test]
    public function throwsOnWildcardOriginCombinedWithCredentials(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000025);
        $this->expectExceptionMessageMatches('/allowCredentials.*wildcard/');

        $this->discover($this->buildContainer(['invalid' => InvalidCorsCredentialsController::class]));
    }

    #[Test]
    public function throwsWhenCorsIsUsedWithoutARoute(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000013);
        $this->expectExceptionMessageMatches('/#\[Cors\].*without a #\[Route\]/');

        $this->discover($this->buildContainer(['orphaned' => OrphanedCorsController::class]));
    }

    #[Test]
    public function throwsOnUnsupportedRateLimitPolicy(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000001);

        $this->discover($this->buildContainer(['bogus' => InvalidRateLimitPolicyController::class]));
    }

    #[Test]
    public function throwsOnUnsupportedRateLimitKey(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000024);

        $this->discover($this->buildContainer(['bogus_key' => InvalidRateLimitKeyController::class]));
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
    public function throwsWhenAnAuthenticatorClassFailsToAutoload(): void
    {
        // BrokenAuthenticator extends a missing class; this must surface as the same LogicException
        // as any other invalid authenticator reference, not as an uncaught \Error.
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000010);

        $this->discover($this->buildContainer(['broken_authenticator' => BrokenAuthenticatorController::class]));
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
    public function acceptsRequestTokenOnADeleteOnlyRoute(): void
    {
        // DELETE is state-changing and CSRF-relevant, so opting into token verification must build.
        $container = $this->buildContainer(['delete_token' => DeleteRequestTokenController::class]);
        (new RouteCompilerPass())->process($container);

        /** @var array<string, string> $scopes */
        $scopes = $container->getDefinition(RouteRegistry::class)->getArgument('$requestTokenScopes');

        self::assertSame('routing/fixture_delete_token', $scopes['fixture_delete_token']);
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
     * @return array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool}>
     */
    private function discover(ContainerBuilder $container): array
    {
        (new RouteCompilerPass())->process($container);

        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null}> $routes */
        $routes = $container->getDefinition(RouteRegistry::class)->getArgument('$routes');

        return $routes;
    }
}
