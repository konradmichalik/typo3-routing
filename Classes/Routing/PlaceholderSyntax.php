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

use function preg_match_all;
use function preg_replace;

use const PREG_SET_ORDER;

/**
 * PlaceholderSyntax.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PlaceholderSyntax
{
    /**
     * Symfony's own inline-syntax pattern (`Route::extractInlineDefaultsAndRequirements()`): a
     * placeholder may carry a "!" prefix, a ":" entity mapping, a "<…>" requirement and a "?…"
     * default. Matching all of them is what makes the plain `{name}` form — the only one argument
     * binding understands — distinguishable from the forms the route compiler silently accepts.
     */
    private const PLACEHOLDER_PATTERN = '#\{!?([\w\x80-\xFF]++)(?::[\w\x80-\xFF]++(?:\.[\w\x80-\xFF]++)?)?(?:<.*?>)?(?:\?[^}]*+)?\}#';

    /**
     * A brace left over once every placeholder is removed: an unclosed `{id`, an empty `{}` or a
     * stray `}`. Symfony treats those as literal path text, which is never what was meant.
     */
    private const STRAY_BRACE_PATTERN = '#\{[^{}]*\}?|\}#';

    /**
     * Every `{…}` fragment of `$path` that is not the plain `{name}` form, which is the only one
     * `ArgumentSpecFactory` recognises as a path placeholder — anything else compiles and matches,
     * but binds its controller argument from the query/body instead of the path.
     *
     * @return list<string>
     */
    public static function unsupported(string $path): array
    {
        $offenders = [];
        preg_match_all(self::PLACEHOLDER_PATTERN, $path, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ('{'.$match[1].'}' !== $match[0]) {
                $offenders[] = $match[0];
            }
        }

        preg_match_all(self::STRAY_BRACE_PATTERN, preg_replace(self::PLACEHOLDER_PATTERN, '', $path) ?? '', $strays);

        return [...$offenders, ...$strays[0]];
    }
}
