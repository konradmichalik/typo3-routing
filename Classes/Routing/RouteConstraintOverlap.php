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

use function array_intersect;
use function array_map;
use function preg_match;
use function preg_match_all;
use function str_contains;
use function strtolower;

/**
 * RouteConstraintOverlap.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RouteConstraintOverlap
{
    /**
     * @param array{path: string, requirements: array<string, string>, schemes?: list<string>, host?: string|null} $a
     * @param array{path: string, requirements: array<string, string>, schemes?: list<string>, host?: string|null} $b
     */
    public function overlaps(array $a, array $b): bool
    {
        if (!$this->schemesOverlap($a['schemes'] ?? [], $b['schemes'] ?? [])) {
            return false;
        }

        if (!$this->hostsOverlap($a['host'] ?? null, $b['host'] ?? null)) {
            return false;
        }

        return $this->requirementsOverlap($a['path'], $a['requirements'], $b['requirements']);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function schemesOverlap(array $a, array $b): bool
    {
        if ([] === $a || [] === $b) {
            return true;
        }

        return [] !== array_intersect(array_map(strtolower(...), $a), array_map(strtolower(...), $b));
    }

    private function hostsOverlap(?string $a, ?string $b): bool
    {
        if (null === $a || null === $b) {
            return true;
        }

        // A placeholder host (e.g. `{subdomain}.example.com`) cannot be proven disjoint this cheaply;
        // only two literal, differing hostnames are treated as never overlapping.
        if (str_contains($a, '{') || str_contains($b, '{')) {
            return true;
        }

        return $a === $b;
    }

    /**
     * @param array<string, string> $requirementsA
     * @param array<string, string> $requirementsB
     */
    private function requirementsOverlap(string $path, array $requirementsA, array $requirementsB): bool
    {
        foreach (self::placeholders($path) as $placeholder) {
            $patternA = $requirementsA[$placeholder] ?? '';
            $patternB = $requirementsB[$placeholder] ?? '';

            if ('' === $patternA || '' === $patternB) {
                continue;
            }

            if (!$this->patternsCanBothMatchAValue($patternA, $patternB)) {
                return false;
            }
        }

        return true;
    }

    /**
     * No general regex-intersection check; probing a handful of representative values (digits, letters,
     * alphanumeric) is enough to catch the common case this check exists for — `\d+` next to `[a-z]+` —
     * without the cost or complexity of proving two arbitrary patterns disjoint in general.
     */
    private function patternsCanBothMatchAValue(string $a, string $b): bool
    {
        foreach (['42', 'abc', 'a1b2'] as $sample) {
            if (1 === preg_match('{^(?:'.$a.')$}sD', $sample) && 1 === preg_match('{^(?:'.$b.')$}sD', $sample)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> placeholder names declared in the path
     */
    private static function placeholders(string $path): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $path, $matches);

        return $matches[1];
    }
}
