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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Http;

use KonradMichalik\Typo3Routing\Http\RequestBody;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\{ServerRequest, Stream};

/**
 * RequestBodyTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(RequestBody::class)]
final class RequestBodyTest extends TestCase
{
    #[Test]
    public function returnsParsedBodyWhenPresent(): void
    {
        $request = (new ServerRequest('https://example.com/', 'POST'))->withParsedBody(['n' => '9']);

        self::assertSame(['n' => '9'], RequestBody::toArray($request));
    }

    #[Test]
    public function parsedBodyTakesPrecedenceOverJsonStream(): void
    {
        $request = $this->jsonRequest('POST', '{"n":1}')->withParsedBody(['n' => '9']);

        self::assertSame(['n' => '9'], RequestBody::toArray($request));
    }

    #[Test]
    public function decodesJsonBodyWhenParsedBodyEmpty(): void
    {
        $request = $this->jsonRequest('POST', '{"n":9,"tags":["a","b"]}');

        self::assertSame(['n' => 9, 'tags' => ['a', 'b']], RequestBody::toArray($request));
    }

    #[Test]
    public function decodesJsonBodyForPutRequest(): void
    {
        $request = $this->jsonRequest('PUT', '{"name":"updated"}');

        self::assertSame(['name' => 'updated'], RequestBody::toArray($request));
    }

    #[Test]
    #[DataProvider('coreFormDecodedMethods')]
    public function ignoresCoreParseStrGarbageForJsonBody(string $method): void
    {
        // TYPO3's ServerRequestFactory::fromGlobals() runs PUT/PATCH/DELETE bodies through
        // parse_str() and calls withParsedBody() whenever the result is non-empty. A JSON body
        // has no `=`/`&`, so it becomes a single garbage key with an empty value.
        $request = $this->jsonRequest($method, '{"quantity":3}')
            ->withParsedBody(['{"quantity":3}' => '']);

        self::assertSame(['quantity' => 3], RequestBody::toArray($request));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function coreFormDecodedMethods(): iterable
    {
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
    }

    #[Test]
    public function decodesJsonSuffixContentType(): void
    {
        $request = $this->jsonRequest('POST', '{"ok":true}', 'application/vnd.api+json');

        self::assertSame(['ok' => true], RequestBody::toArray($request));
    }

    #[Test]
    public function decodesJsonWhenContentTypeCarriesCharset(): void
    {
        $request = $this->jsonRequest('POST', '{"n":1}', 'application/json; charset=utf-8');

        self::assertSame(['n' => 1], RequestBody::toArray($request));
    }

    #[Test]
    public function leavesJsonStreamRewoundForDownstreamReaders(): void
    {
        $request = $this->jsonRequest('POST', '{"n":1}');

        RequestBody::toArray($request);

        self::assertSame('{"n":1}', (string) $request->getBody());
    }

    #[Test]
    public function ignoresJsonStreamWhenContentTypeIsNotJson(): void
    {
        $request = $this->jsonRequest('POST', '{"n":9}', 'text/plain');

        self::assertSame([], RequestBody::toArray($request));
    }

    #[Test]
    public function returnsEmptyArrayForMalformedJson(): void
    {
        $request = $this->jsonRequest('POST', 'not json');

        self::assertSame([], RequestBody::toArray($request));
    }

    #[Test]
    public function returnsEmptyArrayWhenJsonIsNotAnObjectOrArray(): void
    {
        $request = $this->jsonRequest('POST', '42');

        self::assertSame([], RequestBody::toArray($request));
    }

    #[Test]
    public function returnsEmptyArrayWhenBodyIsEmpty(): void
    {
        $request = $this->jsonRequest('POST', '');

        self::assertSame([], RequestBody::toArray($request));
    }

    #[Test]
    public function memoizesTheDecodedBodyPerStream(): void
    {
        $request = $this->jsonRequest('POST', '{"n":1}');
        self::assertSame(['n' => 1], RequestBody::toArray($request));

        // Overwrite the same stream in place; a re-decode would see the new content, but the memo
        // (keyed by the stream instance) returns the first result without touching the stream again.
        $body = $request->getBody();
        $body->rewind();
        $body->write('{"n":2}');
        $body->rewind();
        self::assertSame(['n' => 1], RequestBody::toArray($request));

        // A different request carries a different stream, so it is decoded independently.
        self::assertSame(['n' => 2], RequestBody::toArray($this->jsonRequest('POST', '{"n":2}')));
    }

    private function jsonRequest(string $method, string $body, string $contentType = 'application/json'): ServerRequest
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($body);
        $stream->rewind();

        return (new ServerRequest('https://example.com/', $method))
            ->withBody($stream)
            ->withHeader('Content-Type', $contentType);
    }
}
