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

use KonradMichalik\Typo3Routing\Attribute\Param;
use KonradMichalik\Typo3Routing\DependencyInjection\ParamConstraintGuard;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Entity\Item;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Enum\Status;
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;

/**
 * ParamConstraintGuardTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(ParamConstraintGuard::class)]
final class ParamConstraintGuardTest extends TestCase
{
    #[Test]
    public function returnsNothingWithoutConstraints(): void
    {
        self::assertSame([], $this->constraints(new Param(requirement: '\d+'), 'int'));
    }

    #[Test]
    public function returnsTheRangeOfAnIntParameter(): void
    {
        self::assertSame(['min' => 1, 'max' => 100], $this->constraints(new Param(min: 1, max: 100), 'int'));
    }

    #[Test]
    public function returnsAOneSidedRangeOfAFloatParameter(): void
    {
        self::assertSame(['min' => 0.5], $this->constraints(new Param(min: 0.5), 'float'));
    }

    #[Test]
    public function returnsTheLengthsOfAStringParameter(): void
    {
        self::assertSame(['minLength' => 2, 'maxLength' => 200], $this->constraints(new Param(minLength: 2, maxLength: 200), 'string'));
    }

    #[Test]
    public function acceptsAnEqualMinimumAndMaximum(): void
    {
        self::assertSame(['min' => 5, 'max' => 5, 'minLength' => 0, 'maxLength' => 0], [
            ...$this->constraints(new Param(min: 5, max: 5), 'int'),
            ...$this->constraints(new Param(minLength: 0, maxLength: 0), 'string'),
        ]);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function nonNumericTypes(): iterable
    {
        yield 'string' => ['string'];
        yield 'entity' => [Item::class];
    }

    #[Test]
    #[DataProvider('nonNumericTypes')]
    public function rejectsARangeOnANonNumericType(?string $type): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000044);
        $this->expectExceptionMessage('#[Param(min:/max:)] on "$limit" of "ctrl::list()" needs an int or float parameter');

        $this->constraints(new Param(max: 10), $type);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function nonStringTypes(): iterable
    {
        yield 'int' => ['int'];
        yield 'backed enum' => [Status::class];
    }

    #[Test]
    #[DataProvider('nonStringTypes')]
    public function rejectsALengthOnANonStringType(?string $type): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000045);
        $this->expectExceptionMessage('#[Param(minLength:/maxLength:)] on "$limit" of "ctrl::list()" needs a string parameter');

        $this->constraints(new Param(minLength: 1), $type);
    }

    #[Test]
    public function rejectsAMinimumAboveTheMaximum(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000046);
        $this->expectExceptionMessage('min (10) is greater than max (1)');

        $this->constraints(new Param(min: 10, max: 1), 'int');
    }

    #[Test]
    public function rejectsANegativeLength(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000047);
        $this->expectExceptionMessage('maxLength (-1) is negative');

        $this->constraints(new Param(maxLength: -1), 'string');
    }

    #[Test]
    public function rejectsAMinimumLengthAboveTheMaximumLength(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000048);
        $this->expectExceptionMessage('minLength (5) is greater than maxLength (2)');

        $this->constraints(new Param(minLength: 5, maxLength: 2), 'string');
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFiniteBounds(): iterable
    {
        yield 'INF' => [\INF];
        yield '-INF' => [-\INF];
        yield 'NAN' => [\NAN];
    }

    #[Test]
    #[DataProvider('nonFiniteBounds')]
    public function rejectsANonFiniteBound(float $bound): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000049);
        $this->expectExceptionMessage('is not a finite number');

        $this->constraints(new Param(max: $bound), 'float');
    }

    /**
     * @return iterable<string, array{Param}>
     */
    public static function fractionalBoundsOnInt(): iterable
    {
        yield 'fractional min' => [new Param(min: 1.1)];
        yield 'fractional max' => [new Param(max: 1.9)];
        yield 'whole-number float' => [new Param(max: 5.0)];
        yield 'float beyond 2^53' => [new Param(max: 1e20)];
    }

    #[Test]
    #[DataProvider('fractionalBoundsOnInt')]
    public function rejectsAFloatBoundOnAnIntParameter(Param $param): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000065);
        $this->expectExceptionMessage('#[Param] on "$limit" of "ctrl::list()": an int parameter needs int bounds');

        $this->constraints($param, 'int');
    }

    #[Test]
    public function acceptsAnIntBoundOnAFloatParameter(): void
    {
        self::assertSame(['min' => 1, 'max' => 1.5], $this->constraints(new Param(min: 1, max: 1.5), 'float'));
    }

    #[Test]
    public function acceptsADefaultWithinTheConstraints(): void
    {
        self::assertSame(['max' => 100], $this->constraints(new Param(max: 100), 'int', 100));
    }

    #[Test]
    public function rejectsADefaultBreakingTheConstraints(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000050);
        $this->expectExceptionMessage('#[Param] on "$limit" of "ctrl::list()": the default 500 breaks its own constraints');

        $this->constraints(new Param(max: 100), 'int', 500);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function routeDefaultsBreakingTheConstraints(): iterable
    {
        yield 'int above the maximum' => ['path', 500];
        yield 'numeric string above the maximum' => ['path', '500'];
        yield 'host placeholder above the maximum' => ['host', 500];
    }

    #[Test]
    #[DataProvider('routeDefaultsBreakingTheConstraints')]
    public function rejectsARouteDefaultBreakingTheConstraints(string $source, mixed $default): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000064);
        $this->expectExceptionMessage('#[Route] default for "limit" on "ctrl::list()" is');

        ParamConstraintGuard::assertRouteDefaults([$this->spec('limit', 'int', $source, ['max' => 100])], ['limit' => $default], 'ctrl::list()');
    }

    #[Test]
    public function rejectsANumericRouteDefaultTooLongForAStringParameter(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000064);

        ParamConstraintGuard::assertRouteDefaults([$this->spec('code', 'string', 'path', ['maxLength' => 2])], ['code' => 12345], 'ctrl::list()');
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function routeDefaultsNotChecked(): iterable
    {
        yield 'within the range' => ['path', 100];
        yield 'an input default the matcher never returns' => ['input', 500];
    }

    #[Test]
    #[DataProvider('routeDefaultsNotChecked')]
    public function acceptsARouteDefaultThatCannotFail(string $source, mixed $default): void
    {
        $this->expectNotToPerformAssertions();

        ParamConstraintGuard::assertRouteDefaults([
            $this->spec('limit', 'int', $source, ['max' => 100]),
            $this->spec('page', 'int', 'path', ['min' => 1]),
        ], ['limit' => $default], 'ctrl::list()');
    }

    #[Test]
    public function rejectsACacheIgnoringAConstrainedInput(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000066);
        $this->expectExceptionMessage('#[Cache(ignoreParams:)] on "ctrl::list()" names "limit", which has a #[Param] value constraint');

        ParamConstraintGuard::assertNotIgnoredByCache([$this->spec('limit', 'int', 'input', ['max' => 100])], ['limit'], 'ctrl::list()');
    }

    /**
     * @return iterable<string, array{string, array{min?: int|float, max?: int|float, minLength?: int, maxLength?: int}}>
     */
    public static function argumentsTheCacheMayIgnore(): iterable
    {
        yield 'unconstrained input' => ['input', []];
        yield 'constrained path placeholder, part of the cache key anyway' => ['path', ['max' => 100]];
        yield 'constrained host placeholder, part of the cache key anyway' => ['host', ['max' => 100]];
    }

    /**
     * @param array{min?: int|float, max?: int|float, minLength?: int, maxLength?: int} $constraints
     */
    #[Test]
    #[DataProvider('argumentsTheCacheMayIgnore')]
    public function acceptsACacheIgnoringAnArgumentThatCannotFail(string $source, array $constraints): void
    {
        $this->expectNotToPerformAssertions();

        ParamConstraintGuard::assertNotIgnoredByCache([
            $this->spec('limit', 'int', $source, $constraints),
            $this->spec('page', 'int', 'input', ['min' => 1]),
        ], ['limit'], 'ctrl::list()');
    }

    /**
     * @param array{min?: int|float, max?: int|float, minLength?: int, maxLength?: int} $constraints
     *
     * @return array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed, min?: int|float, max?: int|float, minLength?: int, maxLength?: int}
     */
    private function spec(string $name, string $type, string $source, array $constraints): array
    {
        return ['name' => $name, 'type' => $type, 'source' => $source, 'nullable' => false, 'hasDefault' => false, 'default' => null, ...$constraints];
    }

    /**
     * @return array{min?: int|float, max?: int|float, minLength?: int, maxLength?: int}
     */
    private function constraints(Param $param, ?string $type, mixed $default = null): array
    {
        return ParamConstraintGuard::constraints($param, $type, $default, 'limit', 'ctrl::list()');
    }
}
