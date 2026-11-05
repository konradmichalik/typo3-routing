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
use function array_intersect_key;
use function array_key_exists;
use function in_array;
use function is_finite;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
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

    /**
     * A #[Route] default comes back through the matcher like a request value, so it has to pass the
     * same constraints. It is compared after the coercion the runtime would apply.
     *
     * @param list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed, min?: int|float, max?: int|float, minLength?: int, maxLength?: int}> $specs
     * @param array<string, mixed>                                                                                                                                                               $defaults
     */
    public static function assertRouteDefaults(array $specs, array $defaults, string $where): void
    {
        foreach ($specs as $spec) {
            if (!in_array($spec['source'], ['path', 'host'], true) || !array_key_exists($spec['name'], $defaults)) {
                continue;
            }
            $default = $defaults[$spec['name']];
            if (ArgumentConstraints::violates(self::coerced($default, $spec['type']), $spec)) {
                throw new LogicException(sprintf('#[Route] default for "%s" on "%s" is %s, which breaks the #[Param] constraints of that parameter. Fix the default or the constraints.', $spec['name'], $where, var_export($default, true)), 1750000064);
            }
        }
    }

    /**
     * The response cache answers before arguments are resolved, so a request differing only in an ignored
     * parameter would get the cached 200 instead of its 400. A placeholder is part of the path or host,
     * which the cache key always includes.
     *
     * @param list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed, min?: int|float, max?: int|float, minLength?: int, maxLength?: int}> $specs
     * @param list<string>                                                                                                                                                                       $ignoreParams
     */
    public static function assertNotIgnoredByCache(array $specs, array $ignoreParams, string $where): void
    {
        foreach ($specs as $spec) {
            if (!in_array($spec['name'], $ignoreParams, true) || in_array($spec['source'], ['path', 'host'], true)) {
                continue;
            }
            if ([] !== array_intersect_key($spec, ['min' => 0, 'max' => 0, 'minLength' => 0, 'maxLength' => 0])) {
                throw new LogicException(sprintf('#[Cache(ignoreParams:)] on "%s" names "%s", which has a #[Param] value constraint. A cached response would be served without checking it. Remove it from ignoreParams or drop the constraint.', $where, $spec['name']), 1750000066);
            }
        }
    }

    private static function coerced(mixed $value, ?string $type): mixed
    {
        return match (true) {
            in_array($type, ['int', 'float'], true) && is_string($value) && is_numeric($value) => (float) $value,
            'string' === $type && (is_int($value) || is_float($value)) => (string) $value,
            default => $value,
        };
    }

    private static function assertRange(Param $param, ?string $type, string $parameterName, string $where): void
    {
        if (!in_array($type, ['int', 'float'], true)) {
            throw new LogicException(sprintf('#[Param(min:/max:)] on "$%s" of "%s" needs an int or float parameter, got "%s".', $parameterName, $where, $type ?? 'untyped'), 1750000044);
        }
        if ('int' === $type) {
            self::assertIntBounds($param, $parameterName, $where);
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

    /**
     * Any float is rejected, even 5.0: a fractional range can hold no integer, and a float bound above
     * 2^53 cannot be compared exactly with an int.
     */
    private static function assertIntBounds(Param $param, string $parameterName, string $where): void
    {
        if (is_float($param->min) || is_float($param->max)) {
            throw new LogicException(sprintf('#[Param] on "$%s" of "%s": an int parameter needs int bounds, got min %s and max %s.', $parameterName, $where, var_export($param->min, true), var_export($param->max, true)), 1750000065);
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
