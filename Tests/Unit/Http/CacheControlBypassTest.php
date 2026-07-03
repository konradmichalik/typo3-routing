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

use KonradMichalik\Typo3Routing\Http\CacheControlBypass;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * CacheControlBypassTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(CacheControlBypass::class)]
final class CacheControlBypassTest extends TestCase
{
    #[Test]
    public function neitherReadNorWriteIsSkippedWithoutACacheControlHeader(): void
    {
        $request = new ServerRequest('https://example.com/api/cached', 'GET');

        self::assertFalse(CacheControlBypass::skipsRead($request));
        self::assertFalse(CacheControlBypass::skipsWrite($request));
    }

    #[Test]
    public function noCacheSkipsReadButNotWrite(): void
    {
        $request = (new ServerRequest('https://example.com/api/cached', 'GET'))->withHeader('Cache-Control', 'no-cache');

        self::assertTrue(CacheControlBypass::skipsRead($request));
        self::assertFalse(CacheControlBypass::skipsWrite($request));
    }

    #[Test]
    public function noStoreSkipsBothReadAndWrite(): void
    {
        $request = (new ServerRequest('https://example.com/api/cached', 'GET'))->withHeader('Cache-Control', 'no-store');

        self::assertTrue(CacheControlBypass::skipsRead($request));
        self::assertTrue(CacheControlBypass::skipsWrite($request));
    }

    #[Test]
    public function matchingIsCaseInsensitive(): void
    {
        $request = (new ServerRequest('https://example.com/api/cached', 'GET'))->withHeader('Cache-Control', 'NO-STORE');

        self::assertTrue(CacheControlBypass::skipsRead($request));
        self::assertTrue(CacheControlBypass::skipsWrite($request));
    }
}
