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

use Psr\Container\ContainerInterface;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};

use function hrtime;
use function implode;
use function sprintf;

/*
 * Measures what *obtaining* the RouteDispatcher costs inside a real frontend request, with a warm
 * opcache — the question neither existing tool can answer. `ddev benchmark` pays this cost inside its
 * span but cannot separate it from dispatch, and a CLI measurement is useless for it because CLI runs
 * with opcache off, where the figure is dominated by class loading rather than construction.
 *
 * It exists because that cost turned out to dominate the routing overhead: the dispatcher's dependency
 * graph pulls in roughly twenty classes, each costing ~25-30 µs to load and link, and every frontend
 * request pays for all of them — including ordinary page requests, which the path gate rejects
 * immediately, but only after the graph behind that gate already exists.
 *
 * Two modes, because they exclude each other — a service built once is warm on every later get():
 *
 *   ?probe=graph      (default) one get('RouteDispatcher'), exactly what the middleware stack does.
 *                     This is the per-request cost figure.
 *   ?probe=breakdown  every service individually, in the order below. Each figure is the *marginal*
 *                     cost of that service given everything before it. Diagnostic only: the sum is
 *                     comparable to the graph figure, the individual numbers are not per-request costs.
 *
 * Registered outermost, so it builds the dispatcher before the middleware stack would; the stack then
 * receives the same shared instance for free. That is exactly why it must not be left registered: with
 * it active, the dispatcher's construction cost no longer falls inside the span `ddev benchmark`
 * measures. See Configuration/RequestMiddlewares.php.
 */

/**
 * DiProbeMiddleware.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class DiProbeMiddleware implements MiddlewareInterface
{
    /**
     * The TYPO3 leaves come first on purpose: the site middleware ran before this one, so a real
     * request finds them already built, and charging them to the routing layer would overstate its
     * cost. Their figures should read ~0.000 — that is the assumption being verified, not a dull row.
     */
    private const ORDER = [
        'ExtensionConfiguration', 'CacheManager', 'SiteFinder', 'Context', 'LogManager',
        'RouteRegistry', 'RouteMatcher', 'SiteBasePathResolver', 'ResponseCacheManager',
        'RateLimitCheck', 'ControllerInvoker', 'AccessGuard', 'CorsHandler', 'CorsPreflightResolver',
        'CacheBypassGuard', 'ClientKeyResolver', 'RouteUrlGenerator', 'SiteLanguageScope',
        'DeprecationHeaders', 'RouteDispatcher',
    ];

    public function __construct(private ContainerInterface $services) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Measured before the stack runs, so the dispatcher is genuinely unbuilt at this point.
        if ('breakdown' === ($request->getQueryParams()['probe'] ?? 'graph')) {
            return $handler->handle($request)->withHeader('X-Probe-Di-Breakdown', $this->breakdown());
        }

        $graph = sprintf('%.4f', $this->timeGet('RouteDispatcher'));

        return $handler->handle($request)->withHeader('X-Probe-Di-Graph-Ms', $graph);
    }

    private function breakdown(): string
    {
        $timings = [];
        $total = 0.0;
        foreach (self::ORDER as $label) {
            $ms = $this->timeGet($label);
            $timings[] = $label.'='.sprintf('%.4f', $ms);
            $total += $ms;
        }
        $timings[] = 'TOTAL='.sprintf('%.4f', $total);

        return implode(' ', $timings);
    }

    private function timeGet(string $label): float
    {
        $start = hrtime(true);
        $this->services->get($label);

        return (hrtime(true) - $start) / 1_000_000;
    }
}
