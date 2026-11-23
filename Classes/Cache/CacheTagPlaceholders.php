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

namespace KonradMichalik\Typo3Routing\Cache;

use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

use function array_diff;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function hash;
use function is_scalar;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function str_repeat;
use function substr;

/**
 * CacheTagPlaceholders.
 *
 * Resolves `{name}` placeholders in #[Cache] and #[InvalidatesCache] tags from the matched path values.
 * Storing and flushing both go through resolve(), so the same value always yields the same tag.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class CacheTagPlaceholders
{
    private const PLACEHOLDER = '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/';

    /**
     * Longer values are hashed too: a client-sized value must not push a tag past the 250 characters
     * the cache frontend accepts, which would fail the request after the controller ran.
     */
    private const MAX_VALUE_LENGTH = 64;

    private const SAFE_VALUE = '/^[A-Za-z0-9_-]{1,'.self::MAX_VALUE_LENGTH.'}$/';

    private const INTEGER_VALUE = '/^-?\d+$/';

    private const HASH_FORM = '/^h[0-9a-f]{16}$/';

    /**
     * @return list<string>
     */
    public static function names(string $tagOrPath): array
    {
        preg_match_all(self::PLACEHOLDER, $tagOrPath, $matches);

        return $matches[1];
    }

    /**
     * Placeholders of $tags that $path does not declare. Only path parameters count: a query or body
     * value would let any client create tags at will.
     *
     * @param list<string> $tags
     *
     * @return list<string>
     */
    public static function unbound(array $tags, string $path): array
    {
        $used = array_merge([], ...array_map(self::names(...), $tags));

        return array_values(array_unique(array_diff($used, self::names($path))));
    }

    /**
     * The tag with every placeholder replaced by the longest value resolve() can insert, so validating
     * this against the cache frontend's tag pattern checks the static parts and the length limit.
     */
    public static function withStandIns(string $tag): string
    {
        return (string) preg_replace(self::PLACEHOLDER, str_repeat('x', self::MAX_VALUE_LENGTH), $tag);
    }

    /**
     * Whether the cache frontend accepts the tag with every placeholder at its longest value.
     */
    public static function isValid(string $tag): bool
    {
        return 1 === preg_match(FrontendInterface::PATTERN_TAG, self::withStandIns($tag));
    }

    /**
     * @param list<string>         $tags
     * @param array<string, mixed> $match the matcher result
     *
     * @return list<string>
     */
    public static function resolve(array $tags, array $match): array
    {
        return array_map(
            static fn (string $tag): string => (string) preg_replace_callback(
                self::PLACEHOLDER,
                static fn (array $placeholder): string => self::value($match[$placeholder[1]] ?? null),
                $tag,
            ),
            $tags,
        );
    }

    /**
     * A value outside the safe characters is hashed rather than stripped, and a literal value shaped
     * like a hash form is hashed too, so a literal never equals another value's hash form.
     */
    private static function value(mixed $value): string
    {
        $string = is_scalar($value) ? (string) $value : '';
        // The controller and the core DataHandler see "05" as 5: an extra flush for a slug like "007" beats a missed one.
        if (1 === preg_match(self::INTEGER_VALUE, $string)) {
            $string = (string) (int) $string;
        }

        if (1 === preg_match(self::SAFE_VALUE, $string) && 1 !== preg_match(self::HASH_FORM, $string)) {
            return $string;
        }

        return 'h'.substr(hash('sha256', $string), 0, 16);
    }
}
