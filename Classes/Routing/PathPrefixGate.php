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
use function preg_match;
use function preg_quote;
use function str_starts_with;
use function trim;

/**
 * PathPrefixGate.
 *
 * @internal dispatch plumbing, not part of the metadata surface — see docs/background/extending.md
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
     * Prefixes contributed by routes that opted into case-insensitive matching go into the second list.
     *
     * @param list<string> $prefixes
     * @param list<string> $caseInsensitivePrefixes
     */
    public function __construct(
        private array $prefixes,
        private array $caseInsensitivePrefixes = [],
    ) {}

    /**
     * Parses the comma-separated extension configuration value, mirroring CorsHandler::$allowedOrigins.
     */
    public static function fromCommaList(string $list): self
    {
        return new self(array_values(array_filter(array_map(trim(...), explode(',', $list)), static fn (string $item): bool => '' !== $item)));
    }

    /**
     * The plain (not case-insensitive-folded) prefixes this gate was built from.
     *
     * @return list<string>
     */
    public function prefixes(): array
    {
        return $this->prefixes;
    }

    public function mergedWith(self $other): self
    {
        return new self(
            [...$this->prefixes, ...$other->prefixes],
            [...$this->caseInsensitivePrefixes, ...$other->caseInsensitivePrefixes],
        );
    }

    /**
     * An empty gate matches nothing at all — no route lives behind it and it claims no path. The empty
     * string, contributed by a route whose path starts with a placeholder, matches every path.
     *
     * The case-insensitive tier is skipped entirely while no route opted in, which is the default. It
     * compares via a `/iu`-anchored `preg_match()` rather than `strtolower()`: PHP's `strtolower()` only
     * folds ASCII `A-Z`, so a non-ASCII prefix like `/api/Über` would never match a request for
     * `/api/über` — the exact case-insensitive multibyte matching `CaseInsensitiveRouteCompiler` already
     * performs on the compiled regex itself, which this gate has to agree with or the request never
     * reaches that matcher at all. An invalid-UTF-8 path simply fails every `iu` match rather than
     * erroring, the same "no match" outcome as any other unmatched path.
     */
    public function matches(string $path): bool
    {
        // $path arrives percent-encoded and is compared as such, deliberately — see "Encoded vs.
        // decoded paths" in docs/HOW-IT-WORKS.md.
        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        foreach ($this->caseInsensitivePrefixes as $prefix) {
            if (1 === preg_match('/^'.preg_quote($prefix, '/').'/iu', $path)) {
                return true;
            }
        }

        return false;
    }
}
