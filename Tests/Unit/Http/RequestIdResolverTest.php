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

use KonradMichalik\Typo3Routing\Http\RequestIdResolver;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\{Response, ServerRequest};

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
        $request = (new ServerRequest('https://example.com/api/count', 'GET'))
            ->withHeader('X-Request-ID', 'client-supplied-id');

        $response = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        self::assertSame('client-supplied-id', $response->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function generatesAUuidWhenNoRequestIdWasSent(): void
    {
        $request = new ServerRequest('https://example.com/api/count', 'GET');

        $response = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $response->getHeaderLine('X-Request-ID'),
        );
    }

    #[Test]
    public function generatesADifferentIdOnEachCall(): void
    {
        $request = new ServerRequest('https://example.com/api/count', 'GET');

        $first = RequestIdResolver::decorate(new Response('php://temp', 200), $request);
        $second = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        self::assertNotSame($first->getHeaderLine('X-Request-ID'), $second->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function treatsAWhitespaceOnlyHeaderAsAbsent(): void
    {
        $request = (new ServerRequest('https://example.com/api/count', 'GET'))
            ->withHeader('X-Request-ID', '   ');

        $response = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->getHeaderLine('X-Request-ID'));
    }
}
