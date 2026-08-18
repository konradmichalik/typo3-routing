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
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\{Response, ServerRequest};

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
    public function flushByTagRemovesTaggedEntry(): void
    {
        $this->subject->store('route_tagged', new Response('php://temp', 200), 3600, ['tx_news']);
        self::assertNotNull($this->subject->get('route_tagged'));

        $this->subject->flushByTag('tx_news');

        self::assertNull($this->subject->get('route_tagged'));
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
        $base = $this->subject->buildKey('r', $this->requestWithQuery(['page' => '1', 'search' => 'foo']), ['search']);
        $otherSearch = $this->subject->buildKey('r', $this->requestWithQuery(['page' => '1', 'search' => 'bar']), ['search']);
        $otherPage = $this->subject->buildKey('r', $this->requestWithQuery(['page' => '2', 'search' => 'foo']), ['search']);

        self::assertSame($base, $otherSearch);
        self::assertNotSame($base, $otherPage);
    }

    #[Test]
    public function buildKeyVariesByHost(): void
    {
        // Multi-site: the same route on two domains must never share a cache entry.
        $siteA = $this->subject->buildKey('r', Requests::get('https://a.example.com/api/x')->build(), []);
        $siteB = $this->subject->buildKey('r', Requests::get('https://b.example.com/api/x')->build(), []);

        self::assertNotSame($siteA, $siteB);
    }

    #[Test]
    public function buildKeySharesTheEntryBetweenHeadAndGet(): void
    {
        $get = $this->subject->buildKey('r', Requests::get('https://example.com/api/x')->build(), []);
        $head = $this->subject->buildKey('r', (new RequestBuilder('HEAD', 'https://example.com/api/x'))->build(), []);

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
     * @param array<string, string> $query
     */
    private function requestWithQuery(array $query): ServerRequest
    {
        return Requests::get('https://example.com/api/x')->withQueryParams($query)->build();
    }
}
