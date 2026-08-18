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

use KonradMichalik\Ttt\Http\RequestBuilder;
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
        $request = (new RequestBuilder('POST', 'https://example.com/'))->withParsedBody(['n' => '9'])->build();

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

    #[Test]
    public function decodeErrorDetailIsNullForAValidObjectBody(): void
    {
        self::assertNull(RequestBody::decodeErrorDetail($this->jsonRequest('POST', '{"n":1}')));
    }

    #[Test]
    public function decodeErrorDetailIsNullForAnEmptyBody(): void
    {
        self::assertNull(RequestBody::decodeErrorDetail($this->jsonRequest('POST', '')));
    }

    #[Test]
    public function decodeErrorDetailIsNullForALiteralJsonNull(): void
    {
        self::assertNull(RequestBody::decodeErrorDetail($this->jsonRequest('POST', 'null')));
    }

    #[Test]
    public function decodeErrorDetailIsNullWhenContentTypeIsNotJson(): void
    {
        self::assertNull(RequestBody::decodeErrorDetail($this->jsonRequest('POST', 'not json', 'text/plain')));
    }

    #[Test]
    public function decodeErrorDetailNamesMalformedJsonAsTheCause(): void
    {
        self::assertSame('Malformed JSON request body', RequestBody::decodeErrorDetail($this->jsonRequest('POST', '{"n":')));
    }

    #[Test]
    public function decodeErrorDetailRejectsAScalarBody(): void
    {
        self::assertSame('JSON request body must be a JSON object', RequestBody::decodeErrorDetail($this->jsonRequest('POST', '"just a string"')));
    }

    #[Test]
    public function decodeErrorDetailRejectsANonEmptyJsonArray(): void
    {
        self::assertSame('JSON request body must be a JSON object', RequestBody::decodeErrorDetail($this->jsonRequest('POST', '[1,2,3]')));
    }

    #[Test]
    public function decodeErrorDetailAcceptsAnEmptyJsonArrayAsAnEmptyObject(): void
    {
        self::assertNull(RequestBody::decodeErrorDetail($this->jsonRequest('POST', '[]')));
    }

    #[Test]
    public function decodeErrorDetailMemoizesAlongsideToArray(): void
    {
        $request = $this->jsonRequest('POST', '{"n":');

        self::assertSame([], RequestBody::toArray($request));
        self::assertSame('Malformed JSON request body', RequestBody::decodeErrorDetail($request));
    }

    #[Test]
    public function isNotUnsupportedMediaTypeForJson(): void
    {
        self::assertFalse(RequestBody::isUnsupportedMediaType($this->jsonRequest('POST', '{"n":1}')));
    }

    #[Test]
    #[DataProvider('formEncodedContentTypes')]
    public function isNotUnsupportedMediaTypeForFormEncoding(string $contentType): void
    {
        self::assertFalse(RequestBody::isUnsupportedMediaType($this->jsonRequest('POST', 'n=1', $contentType)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formEncodedContentTypes(): iterable
    {
        yield 'urlencoded' => ['application/x-www-form-urlencoded'];
        yield 'multipart' => ['multipart/form-data; boundary=---x'];
    }

    #[Test]
    public function isUnsupportedMediaTypeForANonEmptyBodyUnderAnUnreadableContentType(): void
    {
        self::assertTrue(RequestBody::isUnsupportedMediaType($this->jsonRequest('POST', 'plain text', 'text/plain')));
    }

    #[Test]
    public function isNotUnsupportedMediaTypeForAnEmptyBodyRegardlessOfContentType(): void
    {
        self::assertFalse(RequestBody::isUnsupportedMediaType($this->jsonRequest('POST', '', 'text/plain')));
    }

    private function jsonRequest(string $method, string $body, string $contentType = 'application/json'): ServerRequest
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($body);
        $stream->rewind();

        return (new RequestBuilder($method, 'https://example.com/'))
            ->build()
            ->withBody($stream)
            ->withHeader('Content-Type', $contentType);
    }
}
