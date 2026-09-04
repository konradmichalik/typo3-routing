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

use function array_diff;
use function array_intersect;
use function array_map;
use function count;
use function implode;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strtoupper;

/**
 * RouteLinter.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RouteLinter
{
    private RouteConstraintOverlap $constraintOverlap;

    public function __construct()
    {
        $this->constraintOverlap = new RouteConstraintOverlap();
    }

    /**
     * @return list<array{severity: 'warning'|'info', check: string, route: string|null, controller: string|null, message: string}>
     */
    public function lint(RouteRegistry $registry, string $exclusivePrefixes = ''): array
    {
        $routes = $registry->getRoutes();

        return [
            ...$this->noStaticPrefixFindings($routes),
            ...$this->duplicatePathFindings($routes),
            ...$this->missingDigitsRequirementFindings($registry, $routes),
            ...$this->unsupportedPlaceholderFindings($routes),
            ...$this->unusedExclusivePrefixFindings($routes, $exclusivePrefixes),
        ];
    }

    /**
     * A route path starting with a placeholder has no static prefix, so it opens the dispatcher's path
     * gate for every request — the matcher alone then decides. Correct and sometimes unavoidable, but
     * invisible to the author unless pointed out.
     *
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool}> $routes
     *
     * @return list<array{severity: 'warning'|'info', check: string, route: string|null, controller: string|null, message: string}>
     */
    private function noStaticPrefixFindings(array $routes): array
    {
        $collection = RouteRegistry::buildCollection($routes);

        $findings = [];
        foreach ($routes as $name => $route) {
            $symfonyRoute = $collection->get($name);
            if (null !== $symfonyRoute && '' === $symfonyRoute->compile()->getStaticPrefix()) {
                $findings[] = [
                    'severity' => 'warning',
                    'check' => 'no-static-prefix',
                    'route' => $name,
                    'controller' => $route['controller'],
                    'message' => sprintf('Path "%s" starts with a placeholder and has no static prefix, so it opens the request-path gate for every request.', $route['path']),
                ];
            }
        }

        return $findings;
    }

    /**
     * Two routes sharing the exact same path and overlapping methods: at equal priority the match
     * order depends on registration order, which the author does not control or see; at unequal
     * priority the lower-priority route is dead the moment the higher one covers all of its methods.
     *
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool}> $routes
     *
     * @return list<array{severity: 'warning'|'info', check: string, route: string|null, controller: string|null, message: string}>
     */
    private function duplicatePathFindings(array $routes): array
    {
        $byPath = [];
        foreach ($routes as $name => $route) {
            $byPath[$route['path']][] = $name;
        }

        $findings = [];
        foreach ($byPath as $names) {
            $findings = [...$findings, ...$this->comparePathGroup($routes, $names)];
        }

        return $findings;
    }

    /**
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool}> $routes
     * @param list<string>                                                                                                                                                                                                                                                                         $names  route names sharing the same path, in registration order
     *
     * @return list<array{severity: 'warning'|'info', check: string, route: string|null, controller: string|null, message: string}>
     */
    private function comparePathGroup(array $routes, array $names): array
    {
        $findings = [];
        $count = count($names);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $finding = $this->compareRoutePair($routes, $names[$i], $names[$j]);
                if (null !== $finding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool}> $routes
     *
     * @return array{severity: 'warning'|'info', check: string, route: string|null, controller: string|null, message: string}|null
     */
    private function compareRoutePair(array $routes, string $earlierName, string $laterName): ?array
    {
        $earlier = $routes[$earlierName];
        $later = $routes[$laterName];

        if (!$this->methodsOverlap($earlier['methods'], $later['methods'])) {
            return null;
        }

        if (!$this->constraintOverlap->overlaps($earlier, $later)) {
            return null;
        }

        $earlierPriority = $earlier['priority'] ?? 0;
        $laterPriority = $later['priority'] ?? 0;

        if ($earlierPriority === $laterPriority) {
            return [
                'severity' => 'warning',
                'check' => 'ambiguous-duplicate-path',
                'route' => $laterName,
                'controller' => $later['controller'],
                'message' => sprintf('Route "%s" shares its path "%s" and an overlapping method with route "%s" at equal priority; match order depends on registration order.', $laterName, $later['path'], $earlierName),
            ];
        }

        [$higherName, $higher, $lowerName, $lower] = $earlierPriority > $laterPriority
            ? [$earlierName, $earlier, $laterName, $later]
            : [$laterName, $later, $earlierName, $earlier];

        if (!$this->methodsFullyCover($higher['methods'], $lower['methods'])) {
            return null;
        }

        return [
            'severity' => 'warning',
            'check' => 'shadowed-route',
            'route' => $lowerName,
            'controller' => $lower['controller'],
            'message' => sprintf('Route "%s" is fully shadowed by route "%s", which shares its path "%s" at a higher priority and covers all of its methods; it can never be reached.', $lowerName, $higherName, $lower['path']),
        ];
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function methodsOverlap(array $a, array $b): bool
    {
        if ([] === $a || [] === $b) {
            return true;
        }

        return [] !== array_intersect(array_map(strtoupper(...), $a), array_map(strtoupper(...), $b));
    }

    /**
     * Whether every method in $covered is already accepted by $covering. An empty list means "any
     * method", which covers everything.
     *
     * @param list<string> $covering
     * @param list<string> $covered
     */
    private function methodsFullyCover(array $covering, array $covered): bool
    {
        if ([] === $covering) {
            return true;
        }

        if ([] === $covered) {
            return false;
        }

        return [] === array_diff(array_map(strtoupper(...), $covered), array_map(strtoupper(...), $covering));
    }

    /**
     * A path placeholder resolved to an `int` argument without a digits requirement matches any
     * non-empty segment; a non-numeric value then fails argument coercion, yielding `400` where the
     * lack of a matching route would normally answer `404`.
     *
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool}> $routes
     *
     * @return list<array{severity: 'warning'|'info', check: string, route: string|null, controller: string|null, message: string}>
     */
    private function missingDigitsRequirementFindings(RouteRegistry $registry, array $routes): array
    {
        $findings = [];
        foreach ($routes as $name => $route) {
            foreach ($registry->getArguments($name) as $argument) {
                if ('path' !== $argument['source'] || 'int' !== $argument['type']) {
                    continue;
                }

                $requirement = $route['requirements'][$argument['name']] ?? null;
                if (null !== $requirement && $this->requirementRejectsNonDigits($requirement)) {
                    continue;
                }

                $findings[] = [
                    'severity' => 'warning',
                    'check' => 'missing-digits-requirement',
                    'route' => $name,
                    'controller' => $route['controller'],
                    'message' => sprintf('Path argument "$%s" is typed int but declares no requirement (e.g. \'\d+\'); a non-numeric value matches the route and then fails argument coercion, yielding 400 instead of 404.', $argument['name']),
                ];
            }
        }

        return $findings;
    }

    /**
     * A requirement such as `[a-z]+` or `\w+` still lets a non-numeric segment reach argument coercion;
     * only a pattern that actually rejects every non-digit probe value protects against the `400`.
     */
    private function requirementRejectsNonDigits(string $requirement): bool
    {
        foreach (['abc', 'a1b2', '-1', '1.5'] as $nonDigitSample) {
            if (1 === preg_match('{^(?:'.$requirement.')$}sD', $nonDigitSample)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Symfony's inline placeholder forms (`{id<\d+>}`, `{page?1}`, `{!page}`, `{user:id}`) are rejected
     * at build time, but a container compiled before that guard existed still carries them — and there
     * the argument is read from the query/body instead of the path. Reported here so the mis-binding
     * surfaces without a container rebuild.
     *
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool}> $routes
     *
     * @return list<array{severity: 'warning'|'info', check: string, route: string|null, controller: string|null, message: string}>
     */
    private function unsupportedPlaceholderFindings(array $routes): array
    {
        $findings = [];
        foreach ($routes as $name => $route) {
            $offenders = PlaceholderSyntax::unsupported($route['path']);
            if ([] === $offenders) {
                continue;
            }

            $findings[] = [
                'severity' => 'warning',
                'check' => 'unsupported-placeholder-syntax',
                'route' => $name,
                'controller' => $route['controller'],
                'message' => sprintf('Path "%s" uses unsupported placeholder syntax ("%s"); it matches, but the controller argument is read from the query/body instead of the path. Write the plain "{name}" form and declare the rest explicitly; the replacement for each inline form is listed under "Inline placeholder syntax" in docs/routes/route-attribute.md.', $route['path'], implode('", "', $offenders)),
            ];
        }

        return $findings;
    }

    /**
     * An exclusive prefix claims a path space for JSON 404s even where no route lives; unintentional,
     * every unmatched path under it silently stops falling through to page rendering.
     *
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool}> $routes
     *
     * @return list<array{severity: 'warning'|'info', check: string, route: string|null, controller: string|null, message: string}>
     */
    private function unusedExclusivePrefixFindings(array $routes, string $exclusivePrefixes): array
    {
        $findings = [];
        foreach (PathPrefixGate::fromCommaList($exclusivePrefixes)->prefixes() as $prefix) {
            if ($this->anyRouteInsidePrefix($routes, $prefix)) {
                continue;
            }

            $findings[] = [
                'severity' => 'info',
                'check' => 'unused-exclusive-prefix',
                'route' => null,
                'controller' => null,
                'message' => sprintf('Exclusive prefix "%s" has no registered route inside it; every unmatched path under it answers a JSON 404 instead of falling through to page rendering.', $prefix),
            ];
        }

        return $findings;
    }

    /**
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool}> $routes
     */
    private function anyRouteInsidePrefix(array $routes, string $prefix): bool
    {
        foreach ($routes as $route) {
            if (str_starts_with($route['path'], $prefix)) {
                return true;
            }
        }

        return false;
    }
}
