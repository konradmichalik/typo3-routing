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
use TYPO3\CMS\Core\Http\{Response, ServerRequest};

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
        $request = $this->request('client-supplied-id');

        $response = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        self::assertSame('client-supplied-id', $response->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function generatesAUuidWhenNoRequestIdWasSent(): void
    {
        $request = $this->request();

        $response = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $response->getHeaderLine('X-Request-ID'),
        );
    }

    #[Test]
    public function generatesADifferentIdOnEachCall(): void
    {
        $request = $this->request();

        $first = RequestIdResolver::decorate(new Response('php://temp', 200), $request);
        $second = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        self::assertNotSame($first->getHeaderLine('X-Request-ID'), $second->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function treatsAWhitespaceOnlyHeaderAsAbsent(): void
    {
        $request = $this->request('   ');

        $response = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function generatesAFreshIdWhenTheIncomingValueContainsControlCharacters(): void
    {
        $request = $this->request("abc\tinjected");

        $response = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        // The tampered value is rejected and a fresh UUID is generated instead.
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function generatesAFreshIdWhenTheIncomingValueIsTooLong(): void
    {
        $request = $this->request(str_repeat('a', 129));

        $response = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function acceptsAValidNonUuidCorrelationId(): void
    {
        $request = $this->request('req_01HXAF.42-abc');

        $response = RequestIdResolver::decorate(new Response('php://temp', 200), $request);

        self::assertSame('req_01HXAF.42-abc', $response->getHeaderLine('X-Request-ID'));
    }

    private function request(?string $requestId = null): ServerRequest
    {
        $builder = Requests::get('https://example.com/api/count');

        if (null !== $requestId) {
            $builder->withHeader('X-Request-ID', $requestId);
        }

        return $builder->build();
    }
}
