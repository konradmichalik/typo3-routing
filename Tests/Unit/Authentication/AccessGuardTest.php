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

use KonradMichalik\Typo3Routing\Authentication\AccessGuard;
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Authentication\{DenyAuthenticator, PassAuthenticator};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Context\{Context, SecurityAspect};
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Security\RequestToken;

/**
 * AccessGuardTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(AccessGuard::class)]
final class AccessGuardTest extends TestCase
{
    #[Test]
    public function grantsAccessToAPublicRoute(): void
    {
        self::assertNull($this->guard()->enforce(['_route' => 'public'], $this->request('GET')));
    }

    #[Test]
    public function grantsAccessWhenTheSingleAuthenticatorAccepts(): void
    {
        self::assertNull($this->guard()->enforce(['_route' => 'secure'], $this->request('GET')));
    }

    #[Test]
    public function deniesWithUnauthorizedWhenEveryAuthenticatorRejects(): void
    {
        $response = $this->guard()->enforce(['_route' => 'denied'], $this->request('GET'));

        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"error":"Unauthorized","status":401}', (string) $response->getBody());
    }

    #[Test]
    public function grantsAccessWhenAtLeastOneAuthenticatorAccepts(): void
    {
        // "multi" is configured with a denying authenticator first, then an accepting one (OR semantics).
        self::assertNull($this->guard()->enforce(['_route' => 'multi'], $this->request('GET')));
    }

    #[Test]
    public function grantsAccessWhenTheRequestTokenScopeMatches(): void
    {
        $guard = $this->guard($this->contextWithToken(RequestToken::create('routing/token')));

        self::assertNull($guard->enforce(['_route' => 'token'], $this->request('POST')));
    }

    #[Test]
    public function deniesWithForbiddenWhenNoRequestTokenWasReceived(): void
    {
        $guard = $this->guard($this->contextWithToken(null));

        $response = $guard->enforce(['_route' => 'token'], $this->request('POST'));

        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"error":"Forbidden","status":403}', (string) $response->getBody());
    }

    #[Test]
    public function deniesWithForbiddenWhenTheRequestTokenIsInvalid(): void
    {
        $guard = $this->guard($this->contextWithToken(false));

        $response = $guard->enforce(['_route' => 'token'], $this->request('POST'));

        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function deniesWithForbiddenWhenTheRequestTokenScopeDoesNotMatch(): void
    {
        $guard = $this->guard($this->contextWithToken(RequestToken::create('routing/other')));

        $response = $guard->enforce(['_route' => 'token'], $this->request('POST'));

        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function skipsTheRequestTokenCheckForSafeMethods(): void
    {
        // "token" requires a token, but a GET is not state-changing and therefore not CSRF-relevant.
        self::assertNull($this->guard()->enforce(['_route' => 'token'], $this->request('GET')));
    }

    private function guard(?Context $context = null): AccessGuard
    {
        $pass = ['service' => PassAuthenticator::class, 'options' => []];
        $deny = ['service' => DenyAuthenticator::class, 'options' => []];

        /** @var array<string, list<array{service: string, options: array<string, mixed>}>> $authenticators */
        $authenticators = [
            'secure' => [$pass],
            'denied' => [$deny],
            'multi' => [$deny, $pass],
        ];
        /** @var array<string, string> $requestTokenScopes */
        $requestTokenScopes = ['token' => 'routing/token'];

        $authenticatorLocator = new ServiceLocator([
            PassAuthenticator::class => static fn (): PassAuthenticator => new PassAuthenticator(),
            DenyAuthenticator::class => static fn (): DenyAuthenticator => new DenyAuthenticator(),
        ]);

        $registry = new RouteRegistry([], new ServiceLocator([]), [], [], [], $authenticators, $requestTokenScopes, $authenticatorLocator);

        return new AccessGuard($registry, $context ?? new Context());
    }

    private function contextWithToken(RequestToken|false|null $token): Context
    {
        $context = new Context();
        SecurityAspect::provideIn($context)->setReceivedRequestToken($token);

        return $context;
    }

    private function request(string $method): ServerRequest
    {
        return new ServerRequest('https://example.com/api/x', $method);
    }
}
