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

namespace KonradMichalik\Typo3Routing\DependencyInjection;

use KonradMichalik\Typo3Routing\Attribute\Param;
use KonradMichalik\Typo3Routing\Routing\ArgumentConstraints;
use LogicException;

use function array_filter;
use function in_array;
use function is_finite;
use function sprintf;
use function var_export;

/**
 * ParamConstraintGuard.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ParamConstraintGuard
{
    /**
     * A range needs an int or float and a length a string, so an untyped, enum or entity parameter is
     * rejected rather than silently ignored. The PHP default is checked here because the runtime never checks it.
     *
     * @return array{min?: int|float, max?: int|float, minLength?: int, maxLength?: int}
     */
    public static function constraints(Param $param, ?string $type, mixed $default, string $parameterName, string $where): array
    {
        if (null !== $param->min || null !== $param->max) {
            self::assertRange($param, $type, $parameterName, $where);
        }
        if (null !== $param->minLength || null !== $param->maxLength) {
            self::assertLengths($param, $type, $parameterName, $where);
        }

        $constraints = array_filter(
            ['min' => $param->min, 'max' => $param->max, 'minLength' => $param->minLength, 'maxLength' => $param->maxLength],
            static fn (int|float|null $value): bool => null !== $value,
        );

        if (ArgumentConstraints::violates($default, $constraints)) {
            throw new LogicException(sprintf('#[Param] on "$%s" of "%s": the default %s breaks its own constraints. Fix the default or the constraints.', $parameterName, $where, var_export($default, true)), 1750000050);
        }

        return $constraints;
    }

    private static function assertRange(Param $param, ?string $type, string $parameterName, string $where): void
    {
        if (!in_array($type, ['int', 'float'], true)) {
            throw new LogicException(sprintf('#[Param(min:/max:)] on "$%s" of "%s" needs an int or float parameter, got "%s".', $parameterName, $where, $type ?? 'untyped'), 1750000044);
        }
        foreach (['min' => $param->min, 'max' => $param->max] as $key => $bound) {
            if (null !== $bound && !is_finite($bound)) {
                throw new LogicException(sprintf('#[Param] on "$%s" of "%s": %s (%s) is not a finite number.', $parameterName, $where, $key, $bound), 1750000049);
            }
        }
        if (null !== $param->min && null !== $param->max && $param->min > $param->max) {
            throw new LogicException(sprintf('#[Param] on "$%s" of "%s": min (%s) is greater than max (%s), so no value could pass.', $parameterName, $where, $param->min, $param->max), 1750000046);
        }
    }

    private static function assertLengths(Param $param, ?string $type, string $parameterName, string $where): void
    {
        if ('string' !== $type) {
            throw new LogicException(sprintf('#[Param(minLength:/maxLength:)] on "$%s" of "%s" needs a string parameter, got "%s".', $parameterName, $where, $type ?? 'untyped'), 1750000045);
        }
        self::assertNotNegative('minLength', $param->minLength, $parameterName, $where);
        self::assertNotNegative('maxLength', $param->maxLength, $parameterName, $where);
        if (null !== $param->minLength && null !== $param->maxLength && $param->minLength > $param->maxLength) {
            throw new LogicException(sprintf('#[Param] on "$%s" of "%s": minLength (%d) is greater than maxLength (%d), so no value could pass.', $parameterName, $where, $param->minLength, $param->maxLength), 1750000048);
        }
    }

    private static function assertNotNegative(string $key, ?int $length, string $parameterName, string $where): void
    {
        if (null !== $length && $length < 0) {
            throw new LogicException(sprintf('#[Param] on "$%s" of "%s": %s (%d) is negative.', $parameterName, $where, $key, $length), 1750000047);
        }
    }
}
