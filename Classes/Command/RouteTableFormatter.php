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

namespace KonradMichalik\Typo3Routing\Command;

use function implode;
use function mb_strlen;
use function mb_substr;
use function sprintf;

/**
 * RouteTableFormatter.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RouteTableFormatter
{
    /**
     * @param array{name: string, path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, schemes: list<string>, host: string|null, description: string|null, auth: list<string>, csrf: string|null, cache: array{lifetime: int, tags: list<string>, ignoreParams: list<string>}|null, rateLimit: array{limit: int, interval: string, policy: string, keyBy: string}|null, arguments: list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>} $row
     *
     * @return list<string>
     */
    public static function tableRow(array $row): array
    {
        return [
            $row['name'],
            $row['path'],
            implode(', ', $row['methods']),
            $row['controller'],
            $row['env'] ?? '-',
            self::requirements($row['requirements']),
            [] === $row['auth'] ? '-' : implode(', ', $row['auth']),
            $row['csrf'] ?? '-',
            self::truncatedDescription($row['description']),
        ];
    }

    /**
     * @param array<string, string> $requirements
     */
    public static function requirements(array $requirements): string
    {
        if ([] === $requirements) {
            return '-';
        }

        $parts = [];
        foreach ($requirements as $parameter => $pattern) {
            $parts[] = $parameter.': '.$pattern;
        }

        return implode(', ', $parts);
    }

    /**
     * @param list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}> $arguments
     */
    public static function arguments(array $arguments): string
    {
        if ([] === $arguments) {
            return '-';
        }

        $parts = [];
        foreach ($arguments as $argument) {
            $type = $argument['type'] ?? 'mixed';
            $suffix = $argument['nullable'] ? '?' : '';
            $parts[] = sprintf('$%s (%s%s, from %s)', $argument['name'], $suffix, $type, $argument['source']);
        }

        return implode(\PHP_EOL, $parts);
    }

    /**
     * Keeps the table readable by truncating long descriptions; the full text is always available
     * via `routing:debug <name>` (detail view) or `--json`.
     */
    public static function truncatedDescription(?string $description): string
    {
        if (null === $description || '' === $description) {
            return '-';
        }

        return mb_strlen($description) > 60 ? mb_substr($description, 0, 59).'…' : $description;
    }
}
