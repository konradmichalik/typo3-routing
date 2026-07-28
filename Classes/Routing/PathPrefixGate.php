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

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function str_starts_with;
use function trim;

/**
 * PathPrefixGate.
 *
 * @internal dispatch plumbing, not part of the metadata surface — see docs/EXTENDING.md
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PathPrefixGate
{
    /**
     * A set of request-path prefixes. The dispatcher builds two of these: one as the performance gate
     * deciding whether a path may reach the matcher at all, one as the exclusive claim deciding whether
     * an unmatched path inside it yields a JSON 404 instead of a page.
     *
     * @param list<string> $prefixes
     */
    public function __construct(private array $prefixes) {}

    /**
     * Parses the comma-separated extension configuration value, mirroring CorsHandler::$allowedOrigins.
     */
    public static function fromCommaList(string $list): self
    {
        return new self(array_values(array_filter(array_map(trim(...), explode(',', $list)), static fn (string $item): bool => '' !== $item)));
    }

    public function mergedWith(self $other): self
    {
        return new self([...$this->prefixes, ...$other->prefixes]);
    }

    /**
     * An empty gate matches nothing at all — no route lives behind it and it claims no path. The empty
     * string, contributed by a route whose path starts with a placeholder, matches every path.
     */
    public function matches(string $path): bool
    {
        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
