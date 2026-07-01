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

use KonradMichalik\Typo3Routing\Http\JsonErrorResponse;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * JsonErrorResponseTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(JsonErrorResponse::class)]
final class JsonErrorResponseTest extends TestCase
{
    #[Test]
    public function buildsAProblemDetailsDocumentWithContentType(): void
    {
        $response = JsonErrorResponse::create(404, 'Not Found');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        // The message equals the generic title, so "detail" is omitted to avoid duplication.
        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Not Found","status":404}', (string) $response->getBody());
    }

    #[Test]
    public function includesDetailWhenItAddsInformationBeyondTheTitle(): void
    {
        $response = JsonErrorResponse::create(400, 'Missing required parameter: id');

        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Bad Request","status":400,"detail":"Missing required parameter: id"}', (string) $response->getBody());
    }

    #[Test]
    public function fallsBackToAGenericTitleForUnmappedStatus(): void
    {
        $response = JsonErrorResponse::create(418, '');

        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Error","status":418}', (string) $response->getBody());
    }

    #[Test]
    public function mergesAdditionalHeaders(): void
    {
        $response = JsonErrorResponse::create(405, 'Method Not Allowed', ['Allow' => 'POST']);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }
}
