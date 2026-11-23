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
    public function anAnonymousRequestIsNotBypassed(): void
    {
        self::assertFalse((new CacheBypassGuard(new Context()))->isBypassed());
    }

    #[Test]
    public function aLoggedInBackendUserIsBypassed(): void
    {
        self::assertTrue((new CacheBypassGuard($this->backendUserContext(true)))->isBypassed());
    }

    #[Test]
    public function aLoggedOutBackendUserIsNotBypassed(): void
    {
        self::assertFalse((new CacheBypassGuard($this->backendUserContext(false)))->isBypassed());
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
