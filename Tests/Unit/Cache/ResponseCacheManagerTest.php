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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Cache;

use KonradMichalik\Ttt\Http\{RequestBuilder, Requests};
use KonradMichalik\Typo3Routing\Cache\ResponseCacheManager;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\CreatesResponseCacheManager;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\{Response, ServerRequest};
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * ResponseCacheManagerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(ResponseCacheManager::class)]
final class ResponseCacheManagerTest extends TestCase
{
    use CreatesResponseCacheManager;

    private ResponseCacheManager $subject;

    protected function setUp(): void
    {
        $this->subject = $this->createResponseCacheManager();
    }

    #[Test]
    public function storesAndRetrievesStatusHeadersAndBody(): void
    {
        $response = new Response('php://temp', 201, ['Content-Type' => 'application/json', 'X-Foo' => 'bar']);
        $response->getBody()->write('{"ok":true}');

        $this->subject->store('route_a', $response, 3600, ['pages']);
        $cached = $this->subject->get('route_a');

        self::assertNotNull($cached);
        self::assertSame(201, $cached->getStatusCode());
        self::assertSame('application/json', $cached->getHeaderLine('Content-Type'));
        self::assertSame('bar', $cached->getHeaderLine('X-Foo'));
        self::assertSame('{"ok":true}', (string) $cached->getBody());
    }

    #[Test]
    public function returnsNullOnCacheMiss(): void
    {
        self::assertNull($this->subject->get('route_missing'));
    }

    #[Test]
    public function doesNotCacheSetCookieHeader(): void
    {
        $response = new Response('php://temp', 200, ['Set-Cookie' => 'sid=abc']);
        $this->subject->store('route_cookie', $response, 60, []);

        self::assertSame('', $this->subject->get('route_cookie')?->getHeaderLine('Set-Cookie'));
    }

    #[Test]
    public function flushByTagsRemovesEveryEntryTaggedWithOneOfThem(): void
    {
        $this->subject->store('route_news', new Response('php://temp', 200), 3600, ['tx_news']);
        $this->subject->store('route_teaser', new Response('php://temp', 200), 3600, ['teaser']);
        $this->subject->store('route_other', new Response('php://temp', 200), 3600, ['other']);

        $this->subject->flushByTags(['tx_news', 'teaser']);

        self::assertNull($this->subject->get('route_news'));
        self::assertNull($this->subject->get('route_teaser'));
        self::assertNotNull($this->subject->get('route_other'));
    }

    #[Test]
    public function withETagAddsAStrongBodyHashAndIsIdempotent(): void
    {
        $response = new Response('php://temp', 200);
        $response->getBody()->write('{"ok":true}');

        $tagged = $this->subject->withETag($response);
        $etag = $tagged->getHeaderLine('ETag');

        self::assertSame('"'.hash('sha256', '{"ok":true}').'"', $etag);
        // The body stays readable after hashing.
        self::assertSame('{"ok":true}', (string) $tagged->getBody());
        // An existing ETag is preserved untouched.
        self::assertSame($etag, $this->subject->withETag($tagged)->getHeaderLine('ETag'));
    }

    #[Test]
    public function withCacheStatusAddsTheHeaderForACacheableGetRoute(): void
    {
        $cacheConfig = ['lifetime' => 3600, 'tags' => [], 'ignoreParams' => []];
        $response = $this->subject->withCacheStatus(new Response('php://temp', 200), $cacheConfig, Requests::get('https://example.com/api/x')->build(), 'HIT');

        self::assertSame('HIT', $response->getHeaderLine('X-TYPO3-API-Cache'));
    }

    #[Test]
    public function withCacheStatusOmitsTheHeaderWhenCachingIsNotConfigured(): void
    {
        $response = $this->subject->withCacheStatus(new Response('php://temp', 200), null, Requests::get('https://example.com/api/x')->build(), 'MISS');

        self::assertSame('', $response->getHeaderLine('X-TYPO3-API-Cache'));
    }

    #[Test]
    public function withCacheStatusOmitsTheHeaderForNonGetRequests(): void
    {
        $cacheConfig = ['lifetime' => 3600, 'tags' => [], 'ignoreParams' => []];
        $response = $this->subject->withCacheStatus(new Response('php://temp', 200), $cacheConfig, Requests::post('https://example.com/api/x')->build(), 'MISS');

        self::assertSame('', $response->getHeaderLine('X-TYPO3-API-Cache'));
    }

    #[Test]
    public function buildKeyIsStableAndHonoursIgnoredParameters(): void
    {
        $arguments = [self::argument('page', 'input'), self::argument('search', 'input')];
        $base = $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '1', 'search' => 'foo']), ['search'], $arguments);
        $otherSearch = $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '1', 'search' => 'bar']), ['search'], $arguments);
        $otherPage = $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '2', 'search' => 'foo']), ['search'], $arguments);

        self::assertSame($base, $otherSearch);
        self::assertNotSame($base, $otherPage);
    }

    #[Test]
    public function buildKeyIgnoresAnUndeclaredQueryParameterOnARouteWithoutARequestArgument(): void
    {
        $arguments = [self::argument('page', 'input')];
        $base = $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '1', 'x' => '1']), [], $arguments);
        $otherUndeclared = $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '1', 'x' => '2']), [], $arguments);
        $withoutUndeclared = $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '1']), [], $arguments);

        self::assertSame($base, $otherUndeclared);
        self::assertSame($base, $withoutUndeclared);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function argumentSources(): iterable
    {
        yield 'query' => ['query', true];
        yield 'input' => ['input', true];
        yield 'variadic' => ['variadic', true];
        yield 'body' => ['body', false];
        yield 'path' => ['path', false];
        yield 'upload' => ['upload', false];
    }

    #[Test]
    #[DataProvider('argumentSources')]
    public function buildKeyVariesOnlyOnTheWireKeyOfAnArgumentReadFromTheQuery(string $source, bool $varies): void
    {
        // The spec name is the wire key: #[Param(name: 'q')] on $term is stored as "q".
        $arguments = [self::argument('q', $source)];
        $one = $this->subject->buildKey(self::match(), $this->requestWithQuery(['q' => '1']), [], $arguments);
        $two = $this->subject->buildKey(self::match(), $this->requestWithQuery(['q' => '2']), [], $arguments);

        self::assertSame($varies, $one !== $two);
    }

    #[Test]
    public function buildKeyVariesOnTheFullQueryWhenTheControllerTakesTheRequest(): void
    {
        $arguments = [self::argument('request', 'request')];
        $base = $this->subject->buildKey(self::match(), $this->requestWithQuery(['x' => '1', 'utm' => 'a']), ['utm'], $arguments);
        $otherUndeclared = $this->subject->buildKey(self::match(), $this->requestWithQuery(['x' => '2', 'utm' => 'a']), ['utm'], $arguments);
        $otherIgnored = $this->subject->buildKey(self::match(), $this->requestWithQuery(['x' => '1', 'utm' => 'b']), ['utm'], $arguments);

        self::assertNotSame($base, $otherUndeclared);
        self::assertSame($base, $otherIgnored);
    }

    #[Test]
    public function buildKeyIsIndependentOfTheQueryParameterOrder(): void
    {
        $arguments = [self::argument('request', 'request')];
        $ab = $this->subject->buildKey(self::match(), $this->requestWithQuery(['a' => '1', 'b' => '2']), [], $arguments);
        $ba = $this->subject->buildKey(self::match(), $this->requestWithQuery(['b' => '2', 'a' => '1']), [], $arguments);

        self::assertSame($ab, $ba);
    }

    #[Test]
    public function buildKeySharesTheEntryAcrossPathVariantsOfOneMatch(): void
    {
        $declared = $this->subject->buildKey(self::match(), Requests::get('https://example.com/api/x')->build(), [], []);
        $trailingSlash = $this->subject->buildKey(self::match(), Requests::get('https://example.com/api/x/')->build(), [], []);
        $leadingSlashes = $this->subject->buildKey(self::match(), Requests::get('https://example.com//api/x')->build(), [], []);
        $case = $this->subject->buildKey(self::match(), Requests::get('https://example.com/API/X')->build(), [], []);

        self::assertSame($declared, $trailingSlash);
        self::assertSame($declared, $leadingSlashes);
        self::assertSame($declared, $case);
    }

    #[Test]
    public function buildKeyVariesByRouteAndPlaceholderValueButNotByMetaKeys(): void
    {
        $base = $this->subject->buildKey(self::match(['id' => '1']), Requests::get('https://example.com/api/x/1')->build(), [], []);
        $otherValue = $this->subject->buildKey(self::match(['id' => '2']), Requests::get('https://example.com/api/x/2')->build(), [], []);
        $otherRoute = $this->subject->buildKey(['_route' => 'other', 'id' => '1'], Requests::get('https://example.com/api/x/1')->build(), [], []);
        $extraMeta = $this->subject->buildKey(self::match(['id' => '1', '_canonicalVariant' => true]), Requests::get('https://example.com/api/x/1/')->build(), [], []);

        self::assertNotSame($base, $otherValue);
        self::assertNotSame($base, $otherRoute);
        self::assertSame($base, $extraMeta);
    }

    #[Test]
    public function buildKeyVariesBySiteOnTheSameHost(): void
    {
        // Two sites on one host with different base paths: the path is no longer in the key, the site is.
        $siteA = $this->subject->buildKey(self::match(), Requests::get('https://example.com/en/api/x')->withAttribute('site', new Site('en', 1, ['base' => '/en/']))->build(), [], []);
        $siteB = $this->subject->buildKey(self::match(), Requests::get('https://example.com/de/api/x')->withAttribute('site', new Site('de', 2, ['base' => '/de/']))->build(), [], []);

        self::assertNotSame($siteA, $siteB);
    }

    #[Test]
    public function buildKeyVariesByHost(): void
    {
        // Multi-site: the same route on two domains must never share a cache entry.
        $siteA = $this->subject->buildKey(self::match(), Requests::get('https://a.example.com/api/x')->build(), [], []);
        $siteB = $this->subject->buildKey(self::match(), Requests::get('https://b.example.com/api/x')->build(), [], []);

        self::assertNotSame($siteA, $siteB);
    }

    #[Test]
    public function buildKeySharesTheEntryBetweenHeadAndGet(): void
    {
        $get = $this->subject->buildKey(self::match(), Requests::get('https://example.com/api/x')->build(), [], []);
        $head = $this->subject->buildKey(self::match(), (new RequestBuilder('HEAD', 'https://example.com/api/x'))->build(), [], []);

        self::assertSame($get, $head);
    }

    #[Test]
    public function withCacheStatusAddsTheHeaderForACacheableHeadRoute(): void
    {
        $cacheConfig = ['lifetime' => 3600, 'tags' => [], 'ignoreParams' => []];
        $response = $this->subject->withCacheStatus(
            new Response('php://temp', 200),
            $cacheConfig,
            (new RequestBuilder('HEAD', 'https://example.com/api/x'))->build(),
            'HIT',
        );

        self::assertSame('HIT', $response->getHeaderLine('X-TYPO3-API-Cache'));
    }

    #[Test]
    public function cacheableMethodCanonicalisesHeadToGet(): void
    {
        self::assertSame('GET', $this->subject->cacheableMethod((new RequestBuilder('HEAD', 'https://example.com/api/x'))->build()));
        self::assertSame('POST', $this->subject->cacheableMethod(Requests::post('https://example.com/api/x')->build()));
    }

    /**
     * @param array<string, mixed> $placeholders
     *
     * @return array<string, mixed>
     */
    private static function match(array $placeholders = []): array
    {
        return ['_route' => 'r', ...$placeholders];
    }

    /**
     * @return array{name: string, source: string}
     */
    private static function argument(string $name, string $source): array
    {
        return ['name' => $name, 'source' => $source];
    }

    /**
     * @param array<string, string> $query
     */
    private function requestWithQuery(array $query): ServerRequest
    {
        return Requests::get('https://example.com/api/x')->withQueryParams($query)->build();
    }
}
