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
 * Standalone benchmark runner. Fires matched routing/plain endpoint pairs against a running
 * TYPO3 frontend and aggregates the per-request `timing.total_ms` recorded by
 * konradmichalik/typo3-request-profiler. Invoked by the `ddev benchmark` command.
 *
 * Usage: php benchmark.php <baseUrl> <count> <profilesDir>
 */

if ($argc < 4) {
    fwrite(\STDERR, "Usage: php benchmark.php <baseUrl> <count> <profilesDir>\n");
    exit(1);
}

$baseUrl = rtrim((string) $argv[1], '/');
$count = max(1, (int) $argv[2]);
$profilesDir = (string) $argv[3];
$warmup = 5;

/*
 * Matched scenario pairs: each routing endpoint has a byte-for-byte identical plain counterpart.
 * The only difference is how the request is dispatched.
 */
$scenarios = [
    'noop (no arguments)' => [
        'routing' => '/api/bench/routing/noop',
        'plain' => '/api/bench/plain/noop',
    ],
    'path parameter {id}' => [
        'routing' => '/api/bench/routing/item/42',
        'plain' => '/api/bench/plain/item/42',
    ],
    'query parameter ?q' => [
        'routing' => '/api/bench/routing/search?q=42',
        'plain' => '/api/bench/plain/search?q=42',
    ],
];

function request(string $url): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        \CURLOPT_RETURNTRANSFER => true,
        \CURLOPT_SSL_VERIFYPEER => false,
        \CURLOPT_SSL_VERIFYHOST => false,
        \CURLOPT_TIMEOUT => 30,
        \CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function percentile(array $values, float $p): float
{
    if ([] === $values) {
        return 0.0;
    }
    sort($values);
    $index = ($p / 100) * (count($values) - 1);
    $lower = (int) floor($index);
    $upper = (int) ceil($index);
    if ($lower === $upper) {
        return $values[$lower];
    }
    $weight = $index - $lower;

    return $values[$lower] * (1 - $weight) + $values[$upper] * $weight;
}

echo "typo3-routing performance benchmark\n";
echo "  base URL : {$baseUrl}\n";
echo "  requests : {$count} per endpoint ({$warmup} warmup, interleaved)\n";
echo "  profiles : {$profilesDir}\n\n";

// 1. Smoke-test every endpoint and bail early on non-200, otherwise the numbers are meaningless.
foreach ($scenarios as $label => $pair) {
    foreach ($pair as $variant => $path) {
        $ch = curl_init($baseUrl.$path);
        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_SSL_VERIFYPEER => false,
            \CURLOPT_SSL_VERIFYHOST => false,
            \CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (200 !== $status) {
            fwrite(\STDERR, "ERROR: {$variant} endpoint {$path} returned HTTP {$status}, not 200.\n");
            fwrite(\STDERR, '       Body: '.substr((string) $body, 0, 300)."\n");
            fwrite(\STDERR, "       Are both fixture extensions installed and caches flushed?\n");
            exit(1);
        }
    }
}

// 2. Clear previous profiles so only this run's requests are aggregated.
if (is_dir($profilesDir)) {
    foreach (glob($profilesDir.'/*.json') ?: [] as $file) {
        @unlink($file);
    }
} else {
    fwrite(\STDERR, "ERROR: profiles directory not found: {$profilesDir}\n");
    fwrite(\STDERR, "       Is konradmichalik/typo3-request-profiler installed and TYPO3_REQUEST_PROFILER_FORCE=1 set?\n");
    exit(1);
}

// 3. Warmup (opcode/realpath caches, autoloader) — not measured.
foreach ($scenarios as $pair) {
    for ($i = 0; $i < $warmup; ++$i) {
        request($baseUrl.$pair['routing']);
        request($baseUrl.$pair['plain']);
    }
}

// Discard warmup profiles too.
foreach (glob($profilesDir.'/*.json') ?: [] as $file) {
    @unlink($file);
}

// 4. Measured run — interleave routing/plain to average out system-load drift.
echo 'Running';
for ($i = 0; $i < $count; ++$i) {
    foreach ($scenarios as $pair) {
        request($baseUrl.$pair['routing']);
        request($baseUrl.$pair['plain']);
    }
    if (0 === $i % 10) {
        echo '.';
    }
}
echo " done.\n\n";

// 5. Aggregate timing.total_ms from the profiles, keyed by request path.
$timingsByPath = [];
foreach (glob($profilesDir.'/*.json') ?: [] as $file) {
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data) || !isset($data['url'], $data['timing']['total_ms'])) {
        continue;
    }
    $path = parse_url((string) $data['url'], \PHP_URL_PATH) ?: '';
    $timingsByPath[$path][] = (float) $data['timing']['total_ms'];
}

function statsFor(array $timingsByPath, string $url): ?array
{
    $path = parse_url($url, \PHP_URL_PATH) ?: '';
    if (empty($timingsByPath[$path])) {
        return null;
    }
    $values = $timingsByPath[$path];

    return [
        'n' => count($values),
        'min' => min($values),
        'avg' => array_sum($values) / count($values),
        'median' => percentile($values, 50),
        'p95' => percentile($values, 95),
        'max' => max($values),
    ];
}

// 6. Report.
$header = sprintf("%-22s %-8s %8s %8s %8s %8s %8s %8s\n", 'Scenario', 'Variant', 'n', 'min', 'avg', 'median', 'p95', 'max');
echo $header;
echo str_repeat('-', strlen($header))."\n";

$verdicts = [];
foreach ($scenarios as $label => $pair) {
    $routing = statsFor($timingsByPath, $pair['routing']);
    $plain = statsFor($timingsByPath, $pair['plain']);
    foreach (['routing' => $routing, 'plain' => $plain] as $variant => $s) {
        if (null === $s) {
            printf("%-22s %-8s %8s\n", $label, $variant, 'n/a');
            continue;
        }
        printf(
            "%-22s %-8s %8d %7.2f %7.2f %7.2f %7.2f %7.2f\n",
            'routing' === $variant ? $label : '',
            $variant,
            $s['n'],
            $s['min'],
            $s['avg'],
            $s['median'],
            $s['p95'],
            $s['max'],
        );
    }
    if (null !== $routing && null !== $plain) {
        $deltaMedian = $routing['median'] - $plain['median'];
        $pct = $plain['median'] > 0 ? ($deltaMedian / $plain['median']) * 100 : 0.0;
        $verdicts[$label] = [$deltaMedian, $pct];
        printf("%-22s %-8s %8s %7s  Δ median %+.2f ms (%+.1f%%)\n", '', '→', '', '', $deltaMedian, $pct);
    }
    echo "\n";
}

echo "Summary (median routing − plain, lower is closer to a hand-rolled middleware):\n";
foreach ($verdicts as $label => [$delta, $pct]) {
    printf("  %-22s %+.2f ms  (%+.1f%%)\n", $label, $delta, $pct);
}
echo "\nAll timings are server-side wall-clock (timing.total_ms) from typo3-request-profiler.\n";
