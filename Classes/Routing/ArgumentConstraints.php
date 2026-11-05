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

namespace KonradMichalik\Typo3Routing\Routing;

use function array_key_exists;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function mb_strlen;

/**
 * ArgumentConstraints.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ArgumentConstraints
{
    /**
     * The #[Param] value constraint keys of an argument spec, keyed for array_intersect_key().
     */
    public const KEYS = ['min' => 0, 'max' => 0, 'minLength' => 0, 'maxLength' => 0];

    /**
     * An empty form field (`?q=`) means "not given" when a PHP default exists, so neither its type nor
     * its constraints are checked. A bool keeps `''` as false.
     *
     * @param array{type: string|null, hasDefault: bool} $spec
     */
    public static function isEmptyWithDefault(mixed $value, array $spec): bool
    {
        return '' === $value && $spec['hasDefault'] && 'bool' !== $spec['type'];
    }

    /**
     * Length counts UTF-8 characters, not bytes, independent of mb_internal_encoding(). A non-finite number ("1e999" as float) never satisfies a range.
     *
     * @param array{min?: int|float, max?: int|float, minLength?: int, maxLength?: int} $constraints
     */
    public static function violates(mixed $value, array $constraints): bool
    {
        if (is_int($value) || is_float($value)) {
            $min = array_key_exists('min', $constraints);
            $max = array_key_exists('max', $constraints);
            if (($min || $max) && !is_finite($value)) {
                return true;
            }

            return ($min && $value < $constraints['min']) || ($max && $value > $constraints['max']);
        }

        if (is_string($value)) {
            $length = mb_strlen($value, 'UTF-8');

            return (array_key_exists('minLength', $constraints) && $length < $constraints['minLength']) || (array_key_exists('maxLength', $constraints) && $length > $constraints['maxLength']);
        }

        return false;
    }
}
