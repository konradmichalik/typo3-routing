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
use PHPUnit\Framework\Attributes\{CoversClass, Test};
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
