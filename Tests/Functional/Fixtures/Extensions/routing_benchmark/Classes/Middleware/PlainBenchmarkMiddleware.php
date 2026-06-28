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

namespace KonradMichalik\RoutingBenchmark\Middleware;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use TYPO3\CMS\Core\Http\JsonResponse;

// The "conventional middleware" side of the benchmark. It does by hand exactly what the
// routing layer does for the BenchmarkController endpoints: match the path, pull and cast
// the relevant input, return the same JSON.
//
// Registered to run at the same stack position as the typo3-routing dispatcher (after the
// site middleware, before the page resolver), so the only thing the benchmark compares is
// the dispatch/argument-resolution machinery, not bootstrap or middleware placement.

/**
 * PlainBenchmarkMiddleware.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class PlainBenchmarkMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if ('/api/bench/plain/noop' === $path) {
            return new JsonResponse(['ok' => true]);
        }

        if (preg_match('#^/api/bench/plain/item/(\d+)$#', $path, $matches)) {
            return new JsonResponse(['id' => (int) $matches[1]]);
        }

        if ('/api/bench/plain/search' === $path) {
            $q = $request->getQueryParams()['q'] ?? null;
            if (null !== $q && preg_match('/^\d+$/', (string) $q)) {
                return new JsonResponse(['q' => (int) $q]);
            }
        }

        return $handler->handle($request);
    }
}
