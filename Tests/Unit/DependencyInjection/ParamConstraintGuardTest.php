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
     * @return array{min?: int|float, max?: int|float, minLength?: int, maxLength?: int}
     */
    private function constraints(Param $param, ?string $type, mixed $default = null): array
    {
        return ParamConstraintGuard::constraints($param, $type, $default, 'limit', 'ctrl::list()');
    }
}
