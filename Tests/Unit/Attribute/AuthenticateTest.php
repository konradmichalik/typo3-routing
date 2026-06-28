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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Attribute;

use KonradMichalik\Typo3Routing\Attribute\Authenticate;
use KonradMichalik\Typo3Routing\Authentication\BearerTokenAuthenticator;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * AuthenticateTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(Authenticate::class)]
final class AuthenticateTest extends TestCase
{
    #[Test]
    public function defaultsToEmptyOptions(): void
    {
        $authenticate = new Authenticate(BearerTokenAuthenticator::class);

        self::assertSame(BearerTokenAuthenticator::class, $authenticate->authenticator);
        self::assertSame([], $authenticate->options);
    }

    #[Test]
    public function storesAuthenticatorAndOptions(): void
    {
        $authenticate = new Authenticate(BearerTokenAuthenticator::class, options: ['envName' => 'PARTNER_A_TOKEN']);

        self::assertSame(BearerTokenAuthenticator::class, $authenticate->authenticator);
        self::assertSame(['envName' => 'PARTNER_A_TOKEN'], $authenticate->options);
    }
}
