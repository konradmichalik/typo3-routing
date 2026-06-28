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

use KonradMichalik\Typo3Routing\Authentication\FrontendUserAuthenticator;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Authentication\FakeUser;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\{Context, UserAspect};
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * FrontendUserAuthenticatorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(FrontendUserAuthenticator::class)]
final class FrontendUserAuthenticatorTest extends TestCase
{
    private ServerRequest $request;

    protected function setUp(): void
    {
        $this->request = new ServerRequest('https://example.com/api');
    }

    #[Test]
    public function acceptsALoggedInUserWithoutGroupConstraint(): void
    {
        $authenticator = new FrontendUserAuthenticator($this->context($this->userAspect(true, [1])));

        self::assertTrue($authenticator->authenticate($this->request));
    }

    #[Test]
    public function rejectsALoggedOutUser(): void
    {
        $authenticator = new FrontendUserAuthenticator($this->context($this->userAspect(false, [])));

        self::assertFalse($authenticator->authenticate($this->request));
    }

    #[Test]
    public function acceptsAUserInOneOfTheRequiredGroups(): void
    {
        $authenticator = new FrontendUserAuthenticator($this->context($this->userAspect(true, [2, 5])));

        self::assertTrue($authenticator->authenticate($this->request, ['groups' => [5, 9]]));
    }

    #[Test]
    public function rejectsAUserInNoneOfTheRequiredGroups(): void
    {
        $authenticator = new FrontendUserAuthenticator($this->context($this->userAspect(true, [2, 5])));

        self::assertFalse($authenticator->authenticate($this->request, ['groups' => [9]]));
    }

    #[Test]
    public function rejectsWhenNoUserAspectWasSet(): void
    {
        // A bare context lazily yields a logged-out UserAspect.
        $authenticator = new FrontendUserAuthenticator(new Context());

        self::assertFalse($authenticator->authenticate($this->request));
    }

    /**
     * @param list<int> $groupIds
     */
    private function userAspect(bool $loggedIn, array $groupIds): UserAspect
    {
        $user = new FakeUser();
        $user->user = ['uid' => $loggedIn ? 1 : 0];

        // Group ids are supplied as the aspect's "alternative groups" so they are returned verbatim.
        return new UserAspect($user, $groupIds);
    }

    private function context(UserAspect $aspect): Context
    {
        $context = new Context();
        $context->setAspect('frontend.user', $aspect);

        return $context;
    }
}
