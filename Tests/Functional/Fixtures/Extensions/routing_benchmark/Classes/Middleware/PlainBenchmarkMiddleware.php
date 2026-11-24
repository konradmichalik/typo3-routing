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

use KonradMichalik\RoutingBenchmark\Domain\Model\Item;
use KonradMichalik\RoutingBenchmark\RateLimit\PlainRateLimitStorage;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\{JsonResponse, Response};
use TYPO3\CMS\Core\Site\Entity\{SiteInterface, SiteLanguage};
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

use function in_array;
use function is_array;
use function is_int;
use function is_string;

// The "conventional middleware" side of the benchmark. It does by hand exactly what the
// routing layer does for the BenchmarkController endpoints: match the path, pull and cast
// the relevant input, return the same JSON. The entity endpoint uses the same
// PersistenceManagerInterface::getObjectByIdentifier() lookup as the routing side, so that
// scenario isolates dispatch overhead specifically, not a difference in lookup mechanism.
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
final readonly class PlainBenchmarkMiddleware implements MiddlewareInterface
{
    public function __construct(
        // Lazy: this middleware runs on every frontend request at this stack position, not just
        // /api/bench/plain/entity/*. Eagerly building PersistenceManagerInterface would pull in
        // PageRepository's constructor-time DB query for every scenario, not only the one that
        // actually needs it.
        #[Lazy] private PersistenceManagerInterface $persistenceManager,
        private CacheManager $cacheManager,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if ('/api/bench/plain/noop' === $path) {
            return new JsonResponse(['ok' => true]);
        }

        if (1 === preg_match('#^/api/bench/plain/item/(\d+)$#', $path, $matches)) {
            return new JsonResponse(['id' => (int) $matches[1]]);
        }

        if ('/api/bench/plain/search' === $path) {
            $q = $request->getQueryParams()['q'] ?? null;
            if (null !== $q && 1 === preg_match('/^\d+$/', (string) $q)) {
                return new JsonResponse(['q' => (int) $q]);
            }
        }

        if (1 === preg_match('#^/api/bench/plain/entity/(\d+)$#', $path, $matches)) {
            $item = $this->persistenceManager->getObjectByIdentifier($matches[1], Item::class);
            if ($item instanceof Item) {
                return new JsonResponse(['id' => $item->getUid(), 'title' => $item->getTitle()]);
            }
        }

        // The hand-rolled counterpart of #[Route(caseInsensitive: true)]: one strcasecmp, so the exact
        // and the differently-cased request cost the same here. That asymmetry against the routing side
        // is the point of the comparison, not a flaw in it.
        //
        // Kept before the v1.2.0 scenarios below on purpose: inserting it later would shift this
        // existing scenario by a few string comparisons and break comparability with the recorded
        // baseline figures.
        if (0 === strcasecmp($path, '/api/bench/plain/ci')) {
            return new JsonResponse(['ok' => true]);
        }

        return $this->deprecated($path)
            ?? $this->scoped($request, $path)
            ?? $this->canonical($path)
            ?? $this->legacy($path)
            ?? $this->cached($request, $path)
            ?? $this->rateLimited($request, $path)
            ?? $handler->handle($request);
    }

    /**
     * Counterpart of #[DeprecatedRoute]: the headers a developer would write by hand, without
     * reimplementing a URL generator for the successor link (nobody would).
     */
    private function deprecated(string $path): ?ResponseInterface
    {
        if ('/api/bench/plain/deprecated' !== $path) {
            return null;
        }

        $response = (new JsonResponse(['ok' => true]))
            ->withHeader('Deprecation', '@1767225600')
            ->withHeader('Sunset', 'Fri, 01 Jan 2027 00:00:00 GMT');

        return $response->withAddedHeader('Link', '</api/bench/plain/noop>; rel="successor-version", <https://example.com/docs/deprecated>; rel="deprecation"');
    }

    /**
     * Counterpart of #[Route(sites:, languages:)]: the two in_array() checks the feature performs,
     * against the same request attributes.
     */
    private function scoped(ServerRequestInterface $request, string $path): ?ResponseInterface
    {
        if ('/api/bench/plain/scoped' !== $path) {
            return null;
        }

        $site = $request->getAttribute('site');
        $language = $request->getAttribute('language');
        if ($site instanceof SiteInterface && in_array($site->getIdentifier(), ['main'], true)
            && $language instanceof SiteLanguage && in_array($language->getLanguageId(), [0], true)) {
            return new JsonResponse(['ok' => true]);
        }

        return null;
    }

    /**
     * Counterpart of #[Route(canonical: true)]: exact path served directly, trailing-slash variant
     * redirected by hand — a literal Location, not a regenerated URL.
     */
    private function canonical(string $path): ?ResponseInterface
    {
        if ('/api/bench/plain/canonical' === $path) {
            return new JsonResponse(['ok' => true]);
        }
        if ('/api/bench/plain/canonical/' === $path) {
            return new Response('php://temp', 308, ['Location' => '/api/bench/plain/canonical']);
        }

        return null;
    }

    /**
     * Counterpart of #[Route(legacyPaths:)], with and without `legacyAlias: true`.
     */
    private function legacy(string $path): ?ResponseInterface
    {
        if ('/api/bench/plain/legacy-new' === $path || '/api/bench/plain/alias-new' === $path || '/api/bench/plain/alias-old' === $path) {
            return new JsonResponse(['ok' => true]);
        }
        if ('/api/bench/plain/legacy-old' === $path) {
            return new Response('php://temp', 308, ['Location' => '/api/bench/plain/legacy-new']);
        }

        return null;
    }

    /**
     * Counterpart of #[Cache(lifetime: 3600)] on a warm entry. It derives a key from the coerced `page`,
     * host, site and language, reads the entry from a VariableFrontend and rebuilds the response with the
     * stored ETag and the X-TYPO3-API-Cache header, the same work the HIT path of the dispatcher does.
     *
     * Not comparable: the routing side also asks the backend-user bypass guard, resolves its argument
     * specs through the registry and the argument resolver (type coercion of every declared argument,
     * not one hand-written digit check), resolves cache tags and answers a conditional GET. The plain
     * side skips all of that, so the delta is an upper bound of what the framework adds over a
     * hand-rolled lookup, and the point of comparison for the key-building change since 1.5.0.
     */
    private function cached(ServerRequestInterface $request, string $path): ?ResponseInterface
    {
        if ('/api/bench/plain/cached' !== $path) {
            return null;
        }

        $rawPage = $request->getQueryParams()['page'] ?? 1;
        if (!is_int($rawPage) && 1 !== preg_match('/^\d+$/', (string) $rawPage)) {
            return null;
        }
        $page = (int) $rawPage;

        $language = $request->getAttribute('language');
        $site = $request->getAttribute('site');
        $key = 'plain_'.hash('sha256', implode('|', [
            'bench_plain_cached',
            'GET',
            $request->getUri()->getHost(),
            $site instanceof SiteInterface ? $site->getIdentifier() : '',
            serialize(['page' => $page]),
            serialize([]),
            (string) ($language instanceof SiteLanguage ? $language->getLanguageId() : 0),
        ]));

        $cache = $this->cacheManager->getCache('routing_benchmark_plain_response');
        $entry = $cache->get($key);
        if (!is_array($entry) || !isset($entry['status'], $entry['headers'], $entry['body'])) {
            $response = new JsonResponse(['page' => $page]);
            $body = (string) $response->getBody();
            $entry = [
                'status' => $response->getStatusCode(),
                'headers' => array_merge($response->getHeaders(), ['ETag' => ['"'.hash('sha256', $body).'"']]),
                'body' => $body,
            ];
            $cache->set($key, $entry, [], 3600);
        }

        $response = new Response('php://temp', (int) $entry['status'], $entry['headers']);
        $response->getBody()->write((string) $entry['body']);
        $response->getBody()->rewind();

        return $response->withHeader('X-TYPO3-API-Cache', 'HIT');
    }

    /**
     * Counterpart of #[RateLimit(limit: 100000, interval: '1 minute')]: one token consumed from a
     * sliding-window limiter whose state lives in a cache, the quota headers stamped on the response.
     * Like the extension, the factory is built per request and without a lock factory.
     *
     * Not comparable: the routing side normalises the client address (IPv6 /64 bucketing, IPv4-mapped
     * addresses) and prefers the normalizedParams attribute over REMOTE_ADDR. Both are a few
     * instructions and the benchmark client is IPv4, so they are not expected to show.
     */
    private function rateLimited(ServerRequestInterface $request, string $path): ?ResponseInterface
    {
        if ('/api/bench/plain/ratelimited' !== $path) {
            return null;
        }

        $remoteAddress = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $factory = new RateLimiterFactory([
            'id' => 'bench_plain_ratelimited',
            'policy' => 'sliding_window',
            'limit' => 100000,
            'interval' => '1 minute',
        ], new PlainRateLimitStorage($this->cacheManager->getCache('routing_benchmark_plain_ratelimit')));

        $result = $factory->create('ip:'.(is_string($remoteAddress) ? $remoteAddress : ''))->consume();

        return (new JsonResponse(['ok' => true]))
            ->withHeader('X-RateLimit-Limit', (string) $result->getLimit())
            ->withHeader('X-RateLimit-Remaining', (string) $result->getRemainingTokens())
            ->withHeader('X-RateLimit-Reset', (string) $result->getRetryAfter()->getTimestamp());
    }
}
