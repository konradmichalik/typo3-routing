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

use function preg_match;

/**
 * RequirementPattern.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RequirementPattern
{
    /**
     * The full-match regex for a requirement, built the way Symfony compiles a placeholder: `{}`
     * delimiters so a `#` or `/` in the requirement stays literal, `D` so `$` does not also match
     * before a trailing newline, and `u` when the route was compiled in UTF-8 mode
     * ({@see RouteRegistry::routeNeedsUtf8()}).
     */
    public static function regex(string $requirement, bool $utf8 = false): string
    {
        return '{^(?:'.$requirement.')$}sD'.($utf8 ? 'u' : '');
    }

    /**
     * Whether `$value` matches `$requirement` as a whole. A regex error counts as no match.
     */
    public static function matches(string $requirement, string $value, bool $utf8 = false): bool
    {
        return 1 === preg_match(self::regex($requirement, $utf8), $value);
    }
}
