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

use KonradMichalik\RoutingBenchmark\Middleware\PlainBenchmarkMiddleware;

return [
    'frontend' => [
        'konradmichalik/routing-benchmark/plain' => [
            'target' => PlainBenchmarkMiddleware::class,
            // Same stack position as the typo3-routing dispatcher (after site, before page-resolver)
            // and explicitly before the dispatcher so the plain /api/bench/plain/* paths short-circuit
            // here instead of being 404'd by the dispatcher's matcher.
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'konradmichalik/typo3-routing/dispatcher',
                'typo3/cms-frontend/page-resolver',
            ],
        ],
        // TYPO3 deep-merges middleware definitions by identifier across all packages, so we can
        // re-order a third-party middleware from here without touching its package. Force the
        // request profiler to wrap (run outside) both benchmark endpoints — otherwise our
        // short-circuiting dispatcher / plain middleware return before the profiler ever runs and
        // no profile is recorded. Both endpoints then share the identical outer span, so the
        // measured delta isolates routing dispatch vs. the hand-rolled middleware.
        'typo3-request-profiler/performance-profiler' => [
            'before' => [
                'konradmichalik/routing-benchmark/plain',
                'konradmichalik/typo3-routing/dispatcher',
            ],
        ],
    ],
];
