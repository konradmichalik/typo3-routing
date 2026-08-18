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

/*
 * Microbenchmark for the two hot paths the HTTP benchmark cannot resolve.
 *
 * benchmark.php measures whole requests, where the run-to-run noise band is roughly ±0.03 ms.
 * The path gate and the matcher fallback operate two to three orders of magnitude below that, so
 * an HTTP comparison can only ever say "no visible difference" about them. This script calls them
 * directly instead, with no TYPO3 bootstrap and no network, which is why its numbers are in
 * microseconds and are actually comparable between two code states.
 *
 * It deliberately covers only the classes that are TYPO3-free: RouteRegistry's static collection
 * builders, PathPrefixGate, CaseInsensitiveRouteCompiler and Symfony's matchers. RouteMatcher
 * itself needs ExtensionConfiguration, so its fallback chain is reproduced here step by step
 * rather than called through.
 *
 * Usage: php matching-microbench.php [iterations]
 */

use KonradMichalik\Typo3Routing\Routing\{CaseInsensitiveRouteCompiler, PathPrefixGate, RouteRegistry};
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\{CompiledUrlMatcher, UrlMatcher};
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\{RequestContext, RouteCollection};

// __DIR__ is always the resolved real path, so this holds when the script is invoked through the
// symlink inside a built .Build/<version> instance too.
require dirname(__DIR__, 8).'/vendor/autoload.php';

$iterations = max(1_000, (int) ($argv[1] ?? 20_000));

/**
 * A route set shaped like a real installation rather than like a fixture: matching cost scales
 * with the collection, so a two-route benchmark would flatter the extension.
 *
 * The shape is spelled out inline rather than through a @phpstan-type alias, because php-cs-fixer's
 * header rules strip the alias declaration and PHPStan then loses the shape.
 *
 * @return array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, caseInsensitive: bool}>
 */
function routeSet(bool $withCaseInsensitive): array
{
    $routes = [];
    for ($i = 0; $i < 20; ++$i) {
        $routes['static_'.$i] = [
            'path' => '/api/resource'.$i.'/list',
            'methods' => ['GET'],
            'controller' => 'C::m',
            'env' => null,
            'requirements' => [],
            'caseInsensitive' => false,
        ];
        $routes['placeholder_'.$i] = [
            'path' => '/api/resource'.$i.'/{id}',
            'methods' => ['GET'],
            'controller' => 'C::m',
            'env' => null,
            'requirements' => ['id' => '\d+'],
            'caseInsensitive' => false,
        ];
    }

    $routes['tolerant'] = [
        'path' => '/api/tolerant/endpoint',
        'methods' => ['GET'],
        'controller' => 'C::m',
        'env' => null,
        'requirements' => [],
        'caseInsensitive' => $withCaseInsensitive,
    ];

    return $routes;
}

/**
 * The fallback collection exactly as RouteRegistry::getCaseInsensitiveCollection() assembles it:
 * the opted-in routes only, each switched to the compiler that adds the "i" modifier.
 *
 * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, caseInsensitive: bool}> $routes
 */
function caseInsensitiveCollection(array $routes): RouteCollection
{
    $collection = RouteRegistry::buildCollection(RouteRegistry::caseInsensitiveRoutes($routes));
    foreach ($collection->all() as $route) {
        $route->setOption('compiler_class', CaseInsensitiveRouteCompiler::class);
    }

    return $collection;
}

/**
 * Any return value the measured closure produces is discarded; only its wall-clock cost matters.
 *
 * @param callable(): mixed $body
 *
 * @return array{per_op_us: float, total_ms: float}
 */
function measure(callable $body, int $iterations): array
{
    // One untimed pass so the first-call regex compilation is not charged to the measurement.
    $body();

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        $body();
    }
    $elapsed = hrtime(true) - $start;

    return [
        'per_op_us' => ($elapsed / $iterations) / 1_000,
        'total_ms' => $elapsed / 1_000_000,
    ];
}

$results = [];

// ---------------------------------------------------------------------------------------------
// 1. The path gate. Runs on EVERY frontend request, including every ordinary page, so this is
//    the only cost the extension imposes on traffic that has nothing to do with routing.
// ---------------------------------------------------------------------------------------------

$plainRoutes = routeSet(false);
$ciRoutes = routeSet(true);

$prefixesWithout = RouteRegistry::staticPrefixes(RouteRegistry::buildCollection($plainRoutes));
$prefixesWith = RouteRegistry::staticPrefixes(RouteRegistry::buildCollection($ciRoutes));
$ciPrefixes = RouteRegistry::staticPrefixes(RouteRegistry::buildCollection(RouteRegistry::caseInsensitiveRoutes($ciRoutes)));

$gateWithout = new PathPrefixGate($prefixesWithout);
$gateWith = new PathPrefixGate($prefixesWith, $ciPrefixes);

$pagePath = '/some/ordinary/page/deep/in/the/tree';

$results['gate: page request, no caseInsensitive route'] = measure(static fn (): bool => $gateWithout->matches($pagePath), $iterations);
$results['gate: page request, one caseInsensitive route'] = measure(static fn (): bool => $gateWith->matches($pagePath), $iterations);
$results['gate: api request (matches on first prefix)'] = measure(static fn (): bool => $gateWith->matches('/api/resource0/list'), $iterations);

// ---------------------------------------------------------------------------------------------
// 2. Matching. The compiled matcher is what an ordinary route hit uses; the fallback chain is
//    what a differently-cased path pays on top.
// ---------------------------------------------------------------------------------------------

$context = new RequestContext();
$context->setMethod('GET');

$compiled = (new CompiledUrlMatcherDumper(RouteRegistry::buildCollection($ciRoutes)))->getCompiledRoutes();
$compiledMatcher = new CompiledUrlMatcher($compiled, $context);

$ciCollection = caseInsensitiveCollection($ciRoutes);

$results['match: static route, exact path'] = measure(static function () use ($compiledMatcher): void {
    $compiledMatcher->match('/api/resource0/list');
}, $iterations);

$results['match: placeholder route, exact path'] = measure(static function () use ($compiledMatcher): void {
    $compiledMatcher->match('/api/resource0/42');
}, $iterations);

$results['match: caseInsensitive route, exact path'] = measure(static function () use ($compiledMatcher): void {
    $compiledMatcher->match('/api/tolerant/endpoint');
}, $iterations);

// The full fallback RouteMatcher walks for a differently-cased path: compiled matcher throws,
// trailing-slash variant throws, then a fresh UrlMatcher over the opted-in collection.
$results['match: caseInsensitive route, mixed casing (full fallback)'] = measure(static function () use ($compiledMatcher, $ciCollection, $context): void {
    $path = '/API/Tolerant/Endpoint';
    try {
        $compiledMatcher->match($path);
    } catch (ResourceNotFoundException) {
        try {
            $compiledMatcher->match($path.'/');
        } catch (ResourceNotFoundException) {
            (new UrlMatcher($ciCollection, $context))->match($path);
        }
    }
}, $iterations);

// The case above reuses one pre-built collection, so Route::compile() has already run and cached
// its result on the Route object. Per real request the registry builds that collection fresh
// (RouteRegistry::getCaseInsensitiveCollection() memoises per request, not across requests), so the
// first match of a request also pays the compilation. This variant includes it and is therefore the
// honest per-request figure for the fallback.
$results['match: mixed casing, collection built fresh (per-request cost)'] = measure(static function () use ($compiledMatcher, $ciRoutes, $context): void {
    $path = '/API/Tolerant/Endpoint';
    try {
        $compiledMatcher->match($path);
    } catch (ResourceNotFoundException) {
        try {
            $compiledMatcher->match($path.'/');
        } catch (ResourceNotFoundException) {
            $collection = caseInsensitiveCollection($ciRoutes);
            (new UrlMatcher($collection, $context))->match($path);
        }
    }
}, $iterations);

// Worst case for a claimed prefix: nothing matches, so every attempt is spent and thrown away.
// Both variants are per-request costs, so both build the fallback collection the way the registry
// does. The pair isolates what a single opted-in route costs every 404 in the installation:
// without one, getCaseInsensitiveMatcher() returns null and there is no third attempt at all.
$results['404: no caseInsensitive route registered'] = measure(static function () use ($compiledMatcher): void {
    $path = '/api/does/not/exist';
    try {
        $compiledMatcher->match($path);
    } catch (ResourceNotFoundException) {
        try {
            $compiledMatcher->match($path.'/');
        } catch (ResourceNotFoundException) {
            // No opted-in route exists, so the dispatcher answers 404 right here.
        }
    }
}, $iterations);

$results['404: one caseInsensitive route registered'] = measure(static function () use ($compiledMatcher, $ciRoutes, $context): void {
    $path = '/api/does/not/exist';
    try {
        $compiledMatcher->match($path);
    } catch (ResourceNotFoundException) {
        try {
            $compiledMatcher->match($path.'/');
        } catch (ResourceNotFoundException) {
            $collection = caseInsensitiveCollection($ciRoutes);
            try {
                (new UrlMatcher($collection, $context))->match($path);
            } catch (ResourceNotFoundException) {
                // The dispatcher answers 404 here.
            }
        }
    }
}, $iterations);

// ---------------------------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------------------------

$placeholderCount = count(array_filter($ciRoutes, static fn (array $r): bool => str_contains($r['path'], '{')));

printf("typo3-routing matching microbenchmark\n");
printf("  PHP        : %s\n", \PHP_VERSION);
printf("  iterations : %s per case\n", number_format($iterations));
printf(
    "  route set  : %d routes (%d static, %d with placeholders, %d caseInsensitive)\n\n",
    count($ciRoutes),
    count($ciRoutes) - $placeholderCount,
    $placeholderCount,
    count(RouteRegistry::caseInsensitiveRoutes($ciRoutes)),
);

$width = max(array_map(strlen(...), array_keys($results)));
$header = sprintf("%-{$width}s %12s %10s\n", 'Case', 'per op', 'total');
echo $header;
echo str_repeat('-', strlen($header))."\n";
foreach ($results as $label => $r) {
    printf("%-{$width}s %9.3f us %7.1f ms\n", $label, $r['per_op_us'], $r['total_ms']);
}

echo "\nDerived:\n";
$gateDelta = $results['gate: page request, one caseInsensitive route']['per_op_us'] - $results['gate: page request, no caseInsensitive route']['per_op_us'];
printf("  gate cost added to every page request by one caseInsensitive route : %+.3f us\n", $gateDelta);
$fallbackDelta = $results['match: mixed casing, collection built fresh (per-request cost)']['per_op_us'] - $results['match: caseInsensitive route, exact path']['per_op_us'];
printf("  extra matching cost of a mixed-cased path over an exact one        : %+.3f us\n", $fallbackDelta);
$notFoundDelta = $results['404: one caseInsensitive route registered']['per_op_us'] - $results['404: no caseInsensitive route registered']['per_op_us'];
printf("  extra cost one opted-in route adds to every 404 under the prefix   : %+.3f us\n", $notFoundDelta);

echo "\nNumbers are single-process, warm-opcache, no HTTP and no TYPO3 bootstrap.\n";
echo "Read them as the cost of the mechanism, not as a request-level figure. For that, see benchmark.php.\n";
