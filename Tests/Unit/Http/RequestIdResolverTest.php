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

use KonradMichalik\Ttt\Http\Requests;
use KonradMichalik\Typo3Routing\Http\RequestIdResolver;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\Response;

use function str_repeat;

/**
 * RequestIdResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RequestIdResolver::class)]
final class RequestIdResolverTest extends TestCase
{
    #[Test]
    public function echoesTheIncomingRequestId(): void
    {
        $request = Requests::get('https://example.com/api/count')
            ->withHeader('X-Request-ID', 'client-supplied-id')
            ->withoutNormalizedParams()->build();

        $response = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        self::assertSame('client-supplied-id', $response->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function generatesAUuidWhenNoRequestIdWasSent(): void
    {
        $request = Requests::get('https://example.com/api/count')->withoutNormalizedParams()->build();

        $response = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $response->getHeaderLine('X-Request-ID'),
        );
    }

    #[Test]
    public function generatesADifferentIdOnEachCall(): void
    {
        $request = Requests::get('https://example.com/api/count')->withoutNormalizedParams()->build();

        $first = RequestIdResolver::decorate(new Response('php://temp', 200), $request);
        $second = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        self::assertNotSame($first->getHeaderLine('X-Request-ID'), $second->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function treatsAWhitespaceOnlyHeaderAsAbsent(): void
    {
        $request = Requests::get('https://example.com/api/count')
            ->withHeader('X-Request-ID', '   ')
            ->withoutNormalizedParams()->build();

        $response = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function generatesAFreshIdWhenTheIncomingValueContainsControlCharacters(): void
    {
        $request = Requests::get('https://example.com/api/count')
            ->withHeader('X-Request-ID', "abc\tinjected")
            ->withoutNormalizedParams()->build();

        $response = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        // The tampered value is rejected and a fresh UUID is generated instead.
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function generatesAFreshIdWhenTheIncomingValueIsTooLong(): void
    {
        $request = Requests::get('https://example.com/api/count')
            ->withHeader('X-Request-ID', str_repeat('a', 129))
            ->withoutNormalizedParams()->build();

        $response = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function acceptsAValidNonUuidCorrelationId(): void
    {
        $request = Requests::get('https://example.com/api/count')
            ->withHeader('X-Request-ID', 'req_01HXAF.42-abc')
            ->withoutNormalizedParams()->build();

        $response = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        self::assertSame('req_01HXAF.42-abc', $response->getHeaderLine('X-Request-ID'));
    }
}
