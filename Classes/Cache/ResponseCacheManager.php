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

use KonradMichalik\Typo3Routing\Http\RequestBody;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Site\Entity\{SiteInterface, SiteLanguage};

use function array_column;
use function array_diff_key;
use function array_filter;
use function array_flip;
use function array_key_exists;
use function in_array;
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

    /**
     * @param list<string> $tags
     */
    public function flushByTags(array $tags): void
    {
        $this->cache()->flushByTags($tags);
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
     * Keyed on what the route consumes, read the way ControllerArgumentResolver reads it, so a client cannot
     * create entries at will. Host and site are part of it, so two sites never share an entry.
     *
     * @param array<string, mixed>                      $match        the matcher result
     * @param list<string>                              $ignoreParams
     * @param list<array{name: string, source: string}> $arguments    the route's argument specs, see RouteRegistry::getArguments()
     */
    public function buildKey(array $match, ServerRequestInterface $request, array $ignoreParams, array $arguments): string
    {
        $language = $request->getAttribute('language');
        $languageId = $language instanceof SiteLanguage ? $language->getLanguageId() : 0;
        $site = $request->getAttribute('site');

        return 'route_'.hash('sha256', implode('|', [
            (string) ($match['_route'] ?? ''),
            $this->cacheableMethod($request),
            $request->getUri()->getHost(),
            $site instanceof SiteInterface ? $site->getIdentifier() : '',
            // serialize(), not json_encode(): it never throws on invalid UTF-8 or INF and keeps every value distinct.
            serialize(self::consumedInputs($match, $request, $ignoreParams, $arguments)),
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

    /**
     * A controller taking the request may read the raw path and any parameter itself, so for such a route
     * those count; otherwise only the values its arguments bind, from the same place the resolver takes them.
     *
     * @param array<string, mixed>                      $match
     * @param list<string>                              $ignoreParams
     * @param list<array{name: string, source: string}> $arguments
     *
     * @return array<string, mixed>
     */
    private static function consumedInputs(array $match, ServerRequestInterface $request, array $ignoreParams, array $arguments): array
    {
        $ignored = array_flip($ignoreParams);
        $query = array_diff_key($request->getQueryParams(), $ignored);
        $body = array_diff_key(RequestBody::toArray($request), $ignored);

        if (in_array('request', array_column($arguments, 'source'), true)) {
            return self::sorted(['path' => $request->getUri()->getPath(), 'query' => self::sorted($query), 'body' => self::sorted($body)]);
        }

        $inputs = array_merge($query, $body);
        $sources = ['path' => $match, 'host' => $match, 'query' => $query, 'body' => $body, 'input' => $inputs, 'variadic' => $inputs];
        $consumed = [];
        foreach ($arguments as $argument) {
            $values = $sources[$argument['source']] ?? [];
            if (array_key_exists($argument['name'], $values)) {
                $consumed[$argument['source'].':'.$argument['name']] = $values[$argument['name']];
            }
        }

        return self::sorted($consumed);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }

    private function cache(): FrontendInterface
    {
        return $this->cache ??= $this->cacheManager->getCache(self::CACHE_IDENTIFIER);
    }
}
