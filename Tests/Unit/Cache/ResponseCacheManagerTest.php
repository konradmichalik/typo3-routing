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
    public function buildKeyIsStableAndHonoursIgnoredParameters(): void
    {
        $base = $this->subject->buildKey('r', $this->requestWithQuery(['page' => '1', 'search' => 'foo']), ['search']);
        $otherSearch = $this->subject->buildKey('r', $this->requestWithQuery(['page' => '1', 'search' => 'bar']), ['search']);
        $otherPage = $this->subject->buildKey('r', $this->requestWithQuery(['page' => '2', 'search' => 'foo']), ['search']);

        self::assertSame($base, $otherSearch);
        self::assertNotSame($base, $otherPage);
    }

    /**
     * @param array<string, string> $query
     */
    private function requestWithQuery(array $query): ServerRequest
    {
        return (new ServerRequest('https://example.com/api/x', 'GET'))->withQueryParams($query);
    }
}
