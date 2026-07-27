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
use KonradMichalik\Typo3Routing\Http\CacheControlBypass;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

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
        $request = Requests::get('https://example.com/api/cached')->withoutNormalizedParams()->build();

        self::assertFalse(CacheControlBypass::skipsRead($request));
        self::assertFalse(CacheControlBypass::skipsWrite($request));
    }

    #[Test]
    public function noCacheSkipsReadButNotWrite(): void
    {
        $request = Requests::get('https://example.com/api/cached')->withHeader('Cache-Control', 'no-cache')->withoutNormalizedParams()->build();

        self::assertTrue(CacheControlBypass::skipsRead($request));
        self::assertFalse(CacheControlBypass::skipsWrite($request));
    }

    #[Test]
    public function noStoreSkipsBothReadAndWrite(): void
    {
        $request = Requests::get('https://example.com/api/cached')->withHeader('Cache-Control', 'no-store')->withoutNormalizedParams()->build();

        self::assertTrue(CacheControlBypass::skipsRead($request));
        self::assertTrue(CacheControlBypass::skipsWrite($request));
    }

    #[Test]
    public function matchingIsCaseInsensitive(): void
    {
        $request = Requests::get('https://example.com/api/cached')->withHeader('Cache-Control', 'NO-STORE')->withoutNormalizedParams()->build();

        self::assertTrue(CacheControlBypass::skipsRead($request));
        self::assertTrue(CacheControlBypass::skipsWrite($request));
    }
}
