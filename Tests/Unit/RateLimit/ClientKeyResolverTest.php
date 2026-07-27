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

namespace KonradMichalik\Typo3Routing\Tests\Unit\RateLimit;

use KonradMichalik\Ttt\Attribute\WithEnvironment;
use KonradMichalik\Ttt\Http\Requests;
use KonradMichalik\Typo3Routing\RateLimit\ClientKeyResolver;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Authentication\FakeUser;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\{Context, UserAspect};

/**
 * ClientKeyResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(ClientKeyResolver::class)]
final class ClientKeyResolverTest extends TestCase
{
    /**
     * @var array{limit: int, interval: string, policy: string, keyBy: string}
     */
    private const IP_CONFIG = ['limit' => 60, 'interval' => '1 minute', 'policy' => 'sliding_window', 'keyBy' => 'ip'];

    /**
     * @var array{limit: int, interval: string, policy: string, keyBy: string}
     */
    private const USER_CONFIG = ['limit' => 60, 'interval' => '1 minute', 'policy' => 'sliding_window', 'keyBy' => 'user'];

    #[Test]
    #[WithEnvironment]
    public function keysByIpFromNormalizedParams(): void
    {
        // Real (not mocked) NormalizedParams — computing getRemoteAddress() requires an initialized Environment.
        $request = Requests::get('https://example.com/api/x')->withRemoteAddress('203.0.113.5')->build();

        self::assertSame('ip:203.0.113.5', $this->resolver()->resolve(self::IP_CONFIG, $request));
    }

    #[Test]
    public function keysByIpFallsBackToRemoteAddrServerParam(): void
    {
        $request = Requests::get('https://example.com/api/x')
            ->withoutNormalizedParams()
            ->withServerParam('REMOTE_ADDR', '198.51.100.9')
            ->build();

        self::assertSame('ip:198.51.100.9', $this->resolver()->resolve(self::IP_CONFIG, $request));
    }

    #[Test]
    public function keysByIpYieldsAnEmptyAddressWhenNoneIsAvailable(): void
    {
        $request = Requests::get('https://example.com/api/x')->withoutNormalizedParams()->build();

        self::assertSame('ip:', $this->resolver()->resolve(self::IP_CONFIG, $request));
    }

    #[Test]
    public function keysByUserWhenAFrontendUserIsLoggedIn(): void
    {
        $request = Requests::get('https://example.com/api/x')->withoutNormalizedParams()->build();

        self::assertSame('user:7', $this->resolver($this->userContext(7))->resolve(self::USER_CONFIG, $request));
    }

    #[Test]
    #[WithEnvironment]
    public function keysByUserFallsBackToIpForAnonymousRequests(): void
    {
        $request = Requests::get('https://example.com/api/x')->withRemoteAddress('203.0.113.5')->build();

        // A user-keyed route still throttles anonymous callers — by IP — since rate limiting runs before auth.
        self::assertSame('ip:203.0.113.5', $this->resolver()->resolve(self::USER_CONFIG, $request));
    }

    private function resolver(?Context $context = null): ClientKeyResolver
    {
        return new ClientKeyResolver($context ?? new Context());
    }

    private function userContext(int $uid): Context
    {
        $user = new FakeUser();
        $user->user = ['uid' => $uid];

        $context = new Context();
        $context->setAspect('frontend.user', new UserAspect($user));

        return $context;
    }
}
