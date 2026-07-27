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

use KonradMichalik\Ttt\Attribute\WithEnvVar;
use KonradMichalik\Ttt\Http\Requests;
use KonradMichalik\Typo3Routing\Authentication\BearerTokenAuthenticator;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * BearerTokenAuthenticatorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(BearerTokenAuthenticator::class)]
final class BearerTokenAuthenticatorTest extends TestCase
{
    private const ENV_NAME = 'ROUTING_TEST_BEARER';

    #[Test]
    #[WithEnvVar(self::ENV_NAME, 's3cret-token')]
    public function acceptsAMatchingToken(): void
    {
        self::assertTrue($this->authenticator()->authenticate($this->request('Bearer s3cret-token'), ['envName' => self::ENV_NAME]));
    }

    #[Test]
    #[WithEnvVar(self::ENV_NAME, 's3cret-token')]
    public function rejectsAWrongToken(): void
    {
        self::assertFalse($this->authenticator()->authenticate($this->request('Bearer wrong'), ['envName' => self::ENV_NAME]));
    }

    #[Test]
    public function failsClosedWhenTheExpectedTokenIsNotSet(): void
    {
        // Variable deliberately unset — the authenticator must reject everything.
        self::assertFalse($this->authenticator()->authenticate($this->request('Bearer anything'), ['envName' => self::ENV_NAME]));
    }

    #[Test]
    #[WithEnvVar(self::ENV_NAME, 's3cret-token')]
    public function rejectsWhenNoAuthorizationHeaderIsPresent(): void
    {
        self::assertFalse($this->authenticator()->authenticate($this->request(), ['envName' => self::ENV_NAME]));
    }

    #[Test]
    #[WithEnvVar(self::ENV_NAME, 's3cret-token')]
    public function rejectsANonBearerAuthorizationScheme(): void
    {
        self::assertFalse($this->authenticator()->authenticate($this->request('Basic s3cret-token'), ['envName' => self::ENV_NAME]));
    }

    #[Test]
    #[WithEnvVar(self::ENV_NAME, 's3cret-token')]
    public function acceptsATokenFromTheRedirectHttpAuthorizationServerParamWhenTheHeaderIsMissing(): void
    {
        $request = Requests::get('https://example.com/api')
            ->withServerParam('REDIRECT_HTTP_AUTHORIZATION', 'Bearer s3cret-token')
            ->withoutNormalizedParams()->build();

        self::assertTrue($this->authenticator()->authenticate($request, ['envName' => self::ENV_NAME]));
    }

    #[Test]
    #[WithEnvVar(self::ENV_NAME, 's3cret-token')]
    public function rejectsWhenNeitherTheHeaderNorTheRedirectServerParamIsPresent(): void
    {
        $request = Requests::get('https://example.com/api')
            ->withServerParam('SOME_OTHER_PARAM', 'irrelevant')
            ->withoutNormalizedParams()->build();

        self::assertFalse($this->authenticator()->authenticate($request, ['envName' => self::ENV_NAME]));
    }

    #[Test]
    #[WithEnvVar(self::ENV_NAME, 'configured-token')]
    public function fallsBackToTheConfiguredEnvNameWhenNoOptionGiven(): void
    {
        self::assertTrue($this->authenticator(self::ENV_NAME)->authenticate($this->request('Bearer configured-token')));
    }

    #[Test]
    #[WithEnvVar('ROUTING_BEARER_TOKEN', 'default-token')]
    public function fallsBackToTheDefaultEnvNameWhenConfigurationThrows(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new RuntimeException('not configured'));

        self::assertTrue((new BearerTokenAuthenticator($extensionConfiguration))->authenticate($this->request('Bearer default-token')));
    }

    private function authenticator(string $configuredEnvName = ''): BearerTokenAuthenticator
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($configuredEnvName);

        return new BearerTokenAuthenticator($extensionConfiguration);
    }

    private function request(?string $authorization = null): ServerRequest
    {
        $builder = Requests::get('https://example.com/api')->withoutNormalizedParams();

        if (null !== $authorization) {
            $builder->withHeader('Authorization', $authorization);
        }

        return $builder->build();
    }
}
