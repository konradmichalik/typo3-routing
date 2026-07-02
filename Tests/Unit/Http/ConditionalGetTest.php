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

use KonradMichalik\Typo3Routing\Http\ConditionalGet;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\{Response, ServerRequest};

/**
 * ConditionalGetTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(ConditionalGet::class)]
final class ConditionalGetTest extends TestCase
{
    #[Test]
    public function returnsNullWhenTheResponseHasNoETag(): void
    {
        $request = $this->request('"abc"');

        self::assertNull(ConditionalGet::notModified($request, new Response('php://temp', 200)));
    }

    #[Test]
    public function returnsNullWhenThereIsNoIfNoneMatchHeader(): void
    {
        $request = new ServerRequest('https://example.com/api/x', 'GET');
        $response = (new Response('php://temp', 200))->withHeader('ETag', '"abc"');

        self::assertNull(ConditionalGet::notModified($request, $response));
    }

    #[Test]
    public function returnsNotModifiedWhenTheValidatorMatches(): void
    {
        $response = (new Response('php://temp', 200))->withHeader('ETag', '"abc"');

        $notModified = ConditionalGet::notModified($this->request('"abc"'), $response);

        self::assertNotNull($notModified);
        self::assertSame(304, $notModified->getStatusCode());
        self::assertSame('"abc"', $notModified->getHeaderLine('ETag'));
        self::assertSame('', (string) $notModified->getBody());
    }

    #[Test]
    public function matchesAnyValidatorWithAWildcard(): void
    {
        $response = (new Response('php://temp', 200))->withHeader('ETag', '"abc"');

        self::assertNotNull(ConditionalGet::notModified($this->request('*'), $response));
    }

    #[Test]
    public function comparesWeaklyAndAcrossAnIfNoneMatchList(): void
    {
        $response = (new Response('php://temp', 200))->withHeader('ETag', '"abc"');

        // Weak validator with the same opaque value, offered within a comma-separated list.
        self::assertNotNull(ConditionalGet::notModified($this->request('"zzz", W/"abc"'), $response));
    }

    #[Test]
    public function returnsNullWhenNoValidatorMatches(): void
    {
        $response = (new Response('php://temp', 200))->withHeader('ETag', '"abc"');

        self::assertNull(ConditionalGet::notModified($this->request('"stale"'), $response));
    }

    private function request(string $ifNoneMatch): ServerRequest
    {
        return (new ServerRequest('https://example.com/api/x', 'GET'))->withHeader('If-None-Match', $ifNoneMatch);
    }
}
