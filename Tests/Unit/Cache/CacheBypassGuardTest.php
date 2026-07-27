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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Cache;

use KonradMichalik\Ttt\Http\Requests;
use KonradMichalik\Typo3Routing\Cache\CacheBypassGuard;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Authentication\FakeUser;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\{Context, UserAspect};

/**
 * CacheBypassGuardTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(CacheBypassGuard::class)]
final class CacheBypassGuardTest extends TestCase
{
    #[Test]
    public function anAnonymousRequestWithoutCacheControlSkipsNeitherReadNorWrite(): void
    {
        $guard = new CacheBypassGuard(new Context());
        $request = Requests::get('https://example.com/api/cached')->withoutNormalizedParams()->build();

        self::assertFalse($guard->skipsRead($request));
        self::assertFalse($guard->skipsWrite($request));
    }

    #[Test]
    public function anAnonymousRequestWithNoStoreSkipsBothReadAndWrite(): void
    {
        $guard = new CacheBypassGuard(new Context());
        $request = Requests::get('https://example.com/api/cached')->withHeader('Cache-Control', 'no-store')->withoutNormalizedParams()->build();

        self::assertTrue($guard->skipsRead($request));
        self::assertTrue($guard->skipsWrite($request));
    }

    #[Test]
    public function aLoggedInBackendUserSkipsBothReadAndWriteRegardlessOfCacheControl(): void
    {
        $guard = new CacheBypassGuard($this->backendUserContext(true));
        $request = Requests::get('https://example.com/api/cached')->withoutNormalizedParams()->build();

        self::assertTrue($guard->skipsRead($request));
        self::assertTrue($guard->skipsWrite($request));
    }

    #[Test]
    public function aLoggedOutBackendUserDoesNotSkipEitherOnItsOwn(): void
    {
        $guard = new CacheBypassGuard($this->backendUserContext(false));
        $request = Requests::get('https://example.com/api/cached')->withoutNormalizedParams()->build();

        self::assertFalse($guard->skipsRead($request));
        self::assertFalse($guard->skipsWrite($request));
    }

    private function backendUserContext(bool $loggedIn): Context
    {
        $user = new FakeUser();
        $user->user = ['uid' => $loggedIn ? 1 : 0];

        $context = new Context();
        $context->setAspect('backend.user', new UserAspect($user));

        return $context;
    }
}
