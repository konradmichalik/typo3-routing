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

namespace KonradMichalik\Typo3Routing\Cache;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

use function is_array;

/**
 * ResponseCacheManager.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ResponseCacheManager
{
    public const CACHE_IDENTIFIER = 'typo3_routing';

    private ?FrontendInterface $cache = null;

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {}

    public function get(string $key): ?ResponseInterface
    {
        $entry = $this->cache()->get($key);
        if (!is_array($entry) || !isset($entry['status'], $entry['headers'], $entry['body'])) {
            return null;
        }

        /** @var array<string, list<string>> $headers */
        $headers = is_array($entry['headers']) ? $entry['headers'] : [];

        $response = new Response('php://temp', (int) $entry['status'], $headers);
        $response->getBody()->write((string) $entry['body']);
        $response->getBody()->rewind();

        return $response;
    }

    /**
     * @param list<string> $tags
     */
    public function store(string $key, ResponseInterface $response, int $lifetime, array $tags): void
    {
        $headers = array_filter(
            $response->getHeaders(),
            static fn (string $name): bool => 'set-cookie' !== strtolower($name),
            \ARRAY_FILTER_USE_KEY,
        );

        $body = $response->getBody();
        $body->rewind();
        $contents = $body->getContents();
        $body->rewind();

        $this->cache()->set($key, [
            'status' => $response->getStatusCode(),
            'headers' => $headers,
            'body' => $contents,
        ], $tags, $lifetime);
    }

    public function flushByTag(string $tag): void
    {
        $this->cache()->flushByTag($tag);
    }

    /**
     * Attaches a strong ETag derived from the response body, unless one is already present. Hashing
     * the body (not the request) makes the validator track the payload: it changes when the content
     * changes — e.g. after a cache-tag flush regenerates a different response for the same request.
     */
    public function withETag(ResponseInterface $response): ResponseInterface
    {
        if ($response->hasHeader('ETag')) {
            return $response;
        }

        $body = $response->getBody();
        $body->rewind();
        $contents = $body->getContents();
        $body->rewind();

        return $response->withHeader('ETag', '"'.hash('sha256', $contents).'"');
    }

    /**
     * Reports whether a cacheable GET route served a stored response or computed one fresh. Absent for
     * routes that never opted into #[Cache] (or a non-GET/HEAD method) — the header's mere presence
     * tells a client whether caching applies to this route at all.
     *
     * @param array{lifetime: int, tags: list<string>, ignoreParams: list<string>}|null $cacheConfig
     */
    public function withCacheStatus(ResponseInterface $response, ?array $cacheConfig, ServerRequestInterface $request, string $status): ResponseInterface
    {
        if (null === $cacheConfig || 'GET' !== $this->cacheableMethod($request)) {
            return $response;
        }

        return $response->withHeader('X-TYPO3-API-Cache', $status);
    }

    /**
     * The host is part of the key: in a multi-site install the same route can serve several sites
     * whose controller output differs per site, and those must never share a cache entry.
     *
     * @param list<string> $ignoreParams
     */
    public function buildKey(string $routeName, ServerRequestInterface $request, array $ignoreParams): string
    {
        $query = $request->getQueryParams();
        foreach ($ignoreParams as $param) {
            unset($query[$param]);
        }
        ksort($query);

        $language = $request->getAttribute('language');
        $languageId = $language instanceof SiteLanguage ? $language->getLanguageId() : 0;

        return 'route_'.hash('sha256', implode('|', [
            $routeName,
            $this->cacheableMethod($request),
            $request->getUri()->getHost(),
            $request->getUri()->getPath(),
            http_build_query($query),
            (string) $languageId,
        ]));
    }

    /**
     * Symfony's matcher canonicalises HEAD to GET, so a HEAD request must key off and gate on the same
     * method GET does, rather than never reading or writing a cache entry at all.
     */
    public function cacheableMethod(ServerRequestInterface $request): string
    {
        return 'HEAD' === $request->getMethod() ? 'GET' : $request->getMethod();
    }

    private function cache(): FrontendInterface
    {
        return $this->cache ??= $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
    }
}
