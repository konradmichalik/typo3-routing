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

use KonradMichalik\Typo3Routing\Attribute\{Authenticate, Cache, Route};
use KonradMichalik\Typo3Routing\DependencyInjection\CompilerWarnings;
use KonradMichalik\Typo3Routing\Routing\RouteControllerInterface;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\{DropsAuthenticateOnOverrideController, DropsOneAliasOnOverrideController, DropsOneInheritedRouteController, InheritsProtectedRouteCleanlyController, NarrowsAuthenticateOnOverrideController, OrCombinedAuthenticateBaseController, OverridesPlainMethodController, ProtectedRouteBaseController, RepeatsAuthenticateOnOverrideController, RepeatsBothAliasesOnOverrideController, RepeatsBothAuthenticateOnOverrideController, TwoAliasRouteBaseController, TwoRouteBaseController};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_WARNING;

/**
 * CompilerWarningsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(CompilerWarnings::class)]
final class CompilerWarningsTest extends TestCase
{
    #[Test]
    public function findOverriddenRouteMethodReturnsNullWhenTheDeclaringClassHasNoParent(): void
    {
        $method = new ReflectionMethod(InheritsProtectedRouteCleanlyController::class, 'detail');

        self::assertNull((new CompilerWarnings())->findOverriddenRouteMethod($method, Route::class));
    }

    #[Test]
    public function findOverriddenRouteMethodReturnsNullWhenTheParentHasNoSameNamedMethod(): void
    {
        $method = new ReflectionMethod(TwoRouteBaseController::class, 'a');

        self::assertNull((new CompilerWarnings())->findOverriddenRouteMethod($method, Route::class));
    }

    #[Test]
    public function findOverriddenRouteMethodReturnsNullWhenTheParentMethodCarriesNoRoute(): void
    {
        $method = new ReflectionMethod(OverridesPlainMethodController::class, 'helper');

        self::assertNull((new CompilerWarnings())->findOverriddenRouteMethod($method, Route::class));
    }

    #[Test]
    public function findOverriddenRouteMethodReturnsTheParentMethodWhenItDeclaresARoute(): void
    {
        $method = new ReflectionMethod(DropsOneInheritedRouteController::class, 'b');

        $overridden = (new CompilerWarnings())->findOverriddenRouteMethod($method, Route::class);

        self::assertInstanceOf(ReflectionMethod::class, $overridden);
        self::assertSame(TwoRouteBaseController::class, $overridden->getDeclaringClass()->getName());
    }

    #[Test]
    public function warnIfControllerHasNoRouteStaysSilentWhenTheControllerHasOne(): void
    {
        $warnings = $this->captureWarnings(static fn () => (new CompilerWarnings())->warnIfControllerHasNoRoute(true, 'some_service', RouteControllerInterface::class));

        self::assertSame([], $warnings);
    }

    #[Test]
    public function warnIfControllerHasNoRouteWarnsWhenTheControllerHasNone(): void
    {
        $warnings = $this->captureWarnings(static fn () => (new CompilerWarnings())->warnIfControllerHasNoRoute(false, 'some_service', RouteControllerInterface::class));

        self::assertCount(1, $warnings);
        self::assertStringContainsString('"some_service" implements', $warnings[0]);
        self::assertStringContainsString('declares no #[Route]', $warnings[0]);
    }

    #[Test]
    public function warnIfRouteWasDroppedStaysSilentWhenThereIsNoOverride(): void
    {
        $method = new ReflectionMethod(TwoRouteBaseController::class, 'a');

        $warnings = $this->captureWarnings(static fn () => (new CompilerWarnings())->warnIfRouteWasDropped(null, $method, 'some_service'));

        self::assertSame([], $warnings);
    }

    #[Test]
    public function warnIfRouteWasDroppedWarnsWhenAnOverrideExists(): void
    {
        $overridden = new ReflectionMethod(TwoRouteBaseController::class, 'b');
        $override = new ReflectionMethod(DropsOneInheritedRouteController::class, 'b');

        $warnings = $this->captureWarnings(static fn () => (new CompilerWarnings())->warnIfRouteWasDropped($overridden, $override, 'drops_one'));

        self::assertCount(1, $warnings);
        self::assertStringContainsString('"drops_one::b()" overrides', $warnings[0]);
        self::assertStringContainsString('does not repeat all its #[Route] attributes', $warnings[0]);
        self::assertStringContainsString('route_b', $warnings[0]);
    }

    #[Test]
    public function warnIfRouteWasDroppedWarnsWhenAnOverrideKeepsOnlyOneOfTwoAliases(): void
    {
        $overridden = new ReflectionMethod(TwoAliasRouteBaseController::class, 'list');
        $override = new ReflectionMethod(DropsOneAliasOnOverrideController::class, 'list');

        $warnings = $this->captureWarnings(static fn () => (new CompilerWarnings())->warnIfRouteWasDropped($overridden, $override, 'drops_alias'));

        self::assertCount(1, $warnings);
        self::assertStringContainsString('"drops_alias::list()" overrides', $warnings[0]);
        self::assertStringContainsString('alias_b', $warnings[0]);
        self::assertStringNotContainsString('alias_a,', $warnings[0]);
    }

    #[Test]
    public function warnIfRouteWasDroppedStaysSilentWhenBothAliasesAreRepeatedInAnyOrder(): void
    {
        $overridden = new ReflectionMethod(TwoAliasRouteBaseController::class, 'list');
        $override = new ReflectionMethod(RepeatsBothAliasesOnOverrideController::class, 'list');

        $warnings = $this->captureWarnings(static fn () => (new CompilerWarnings())->warnIfRouteWasDropped($overridden, $override, 'repeats_both'));

        self::assertSame([], $warnings);
    }

    #[Test]
    public function warnIfAModifierWasDroppedStaysSilentWhenThereIsNoOverride(): void
    {
        $method = new ReflectionMethod(ProtectedRouteBaseController::class, 'detail');

        $warnings = $this->captureWarnings(static fn () => (new CompilerWarnings())->warnIfAModifierWasDropped(null, $method, 'some_service', [Authenticate::class => '#[Authenticate]']));

        self::assertSame([], $warnings);
    }

    #[Test]
    public function warnIfAModifierWasDroppedStaysSilentWhenNothingWasDropped(): void
    {
        $overridden = new ReflectionMethod(ProtectedRouteBaseController::class, 'detail');
        $override = new ReflectionMethod(RepeatsAuthenticateOnOverrideController::class, 'detail');

        $warnings = $this->captureWarnings(static fn () => (new CompilerWarnings())->warnIfAModifierWasDropped($overridden, $override, 'repeats', [Authenticate::class => '#[Authenticate]']));

        self::assertSame([], $warnings);
    }

    #[Test]
    public function warnIfAModifierWasDroppedWarnsWithTheDroppedLabel(): void
    {
        $overridden = new ReflectionMethod(ProtectedRouteBaseController::class, 'detail');
        $override = new ReflectionMethod(DropsAuthenticateOnOverrideController::class, 'detail');

        $warnings = $this->captureWarnings(static fn () => (new CompilerWarnings())->warnIfAModifierWasDropped($overridden, $override, 'drops_auth', [Authenticate::class => '#[Authenticate]']));

        self::assertCount(1, $warnings);
        self::assertStringContainsString('"drops_auth::detail()" overrides', $warnings[0]);
        self::assertStringContainsString('drops #[Authenticate]', $warnings[0]);
    }

    #[Test]
    public function warnIfAModifierWasDroppedWarnsWhenAnOverrideKeepsOnlyOneOfTwoOrCombinedInstances(): void
    {
        $overridden = new ReflectionMethod(OrCombinedAuthenticateBaseController::class, 'detail');
        $override = new ReflectionMethod(NarrowsAuthenticateOnOverrideController::class, 'detail');

        $warnings = $this->captureWarnings(static fn () => (new CompilerWarnings())->warnIfAModifierWasDropped($overridden, $override, 'narrows', [Authenticate::class => '#[Authenticate]']));

        self::assertCount(1, $warnings);
        self::assertStringContainsString('"narrows::detail()" overrides', $warnings[0]);
        self::assertStringContainsString('drops #[Authenticate]', $warnings[0]);
    }

    #[Test]
    public function warnIfAModifierWasDroppedStaysSilentWhenBothOrCombinedInstancesAreRepeatedInAnyOrder(): void
    {
        $overridden = new ReflectionMethod(OrCombinedAuthenticateBaseController::class, 'detail');
        $override = new ReflectionMethod(RepeatsBothAuthenticateOnOverrideController::class, 'detail');

        $warnings = $this->captureWarnings(static fn () => (new CompilerWarnings())->warnIfAModifierWasDropped($overridden, $override, 'repeats_both', [Authenticate::class => '#[Authenticate]']));

        self::assertSame([], $warnings);
    }

    #[Test]
    public function warnIfAModifierWasDroppedIgnoresAModifierClassTheParentNeverHad(): void
    {
        $overridden = new ReflectionMethod(ProtectedRouteBaseController::class, 'detail');
        $override = new ReflectionMethod(RepeatsAuthenticateOnOverrideController::class, 'detail');

        // The parent has #[Authenticate] but no #[Cache]; nothing to drop for a class it never had.
        $warnings = $this->captureWarnings(static fn () => (new CompilerWarnings())->warnIfAModifierWasDropped($overridden, $override, 'repeats', [Cache::class => '#[Cache]']));

        self::assertSame([], $warnings);
    }

    /**
     * @return list<string>
     */
    private function captureWarnings(callable $callback): array
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        }, E_USER_WARNING);

        try {
            $callback();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }
}
