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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Authentication;

use KonradMichalik\Typo3Routing\Authentication\BackendUserAuthenticator;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Authentication\FakeUser;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\{Context, UserAspect};
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * BackendUserAuthenticatorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(BackendUserAuthenticator::class)]
final class BackendUserAuthenticatorTest extends TestCase
{
    private ServerRequest $request;

    protected function setUp(): void
    {
        $this->request = new ServerRequest('https://example.com/api');
    }

    #[Test]
    public function acceptsALoggedInBackendUser(): void
    {
        self::assertTrue((new BackendUserAuthenticator($this->context(true)))->authenticate($this->request));
    }

    #[Test]
    public function rejectsALoggedOutBackendUser(): void
    {
        self::assertFalse((new BackendUserAuthenticator($this->context(false)))->authenticate($this->request));
    }

    #[Test]
    public function rejectsWhenTheAspectIsMissing(): void
    {
        self::assertFalse((new BackendUserAuthenticator(new Context()))->authenticate($this->request));
    }

    private function context(bool $loggedIn): Context
    {
        $user = new FakeUser();
        $user->user = ['uid' => $loggedIn ? 1 : 0];

        $context = new Context();
        $context->setAspect('backend.user', new UserAspect($user));

        return $context;
    }
}
