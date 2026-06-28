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

    protected function tearDown(): void
    {
        unset($_ENV[self::ENV_NAME]);
    }

    #[Test]
    public function acceptsAMatchingToken(): void
    {
        $_ENV[self::ENV_NAME] = 's3cret-token';

        $request = (new ServerRequest('https://example.com/api'))->withHeader('Authorization', 'Bearer s3cret-token');

        self::assertTrue($this->authenticator()->authenticate($request, ['envName' => self::ENV_NAME]));
    }

    #[Test]
    public function rejectsAWrongToken(): void
    {
        $_ENV[self::ENV_NAME] = 's3cret-token';

        $request = (new ServerRequest('https://example.com/api'))->withHeader('Authorization', 'Bearer wrong');

        self::assertFalse($this->authenticator()->authenticate($request, ['envName' => self::ENV_NAME]));
    }

    #[Test]
    public function failsClosedWhenTheExpectedTokenIsNotSet(): void
    {
        // Variable deliberately unset — the authenticator must reject everything.
        $request = (new ServerRequest('https://example.com/api'))->withHeader('Authorization', 'Bearer anything');

        self::assertFalse($this->authenticator()->authenticate($request, ['envName' => self::ENV_NAME]));
    }

    #[Test]
    public function rejectsWhenNoAuthorizationHeaderIsPresent(): void
    {
        $_ENV[self::ENV_NAME] = 's3cret-token';

        self::assertFalse($this->authenticator()->authenticate(new ServerRequest('https://example.com/api'), ['envName' => self::ENV_NAME]));
    }

    #[Test]
    public function rejectsANonBearerAuthorizationScheme(): void
    {
        $_ENV[self::ENV_NAME] = 's3cret-token';

        $request = (new ServerRequest('https://example.com/api'))->withHeader('Authorization', 'Basic s3cret-token');

        self::assertFalse($this->authenticator()->authenticate($request, ['envName' => self::ENV_NAME]));
    }

    #[Test]
    public function fallsBackToTheConfiguredEnvNameWhenNoOptionGiven(): void
    {
        $_ENV[self::ENV_NAME] = 'configured-token';

        $request = (new ServerRequest('https://example.com/api'))->withHeader('Authorization', 'Bearer configured-token');

        self::assertTrue($this->authenticator(self::ENV_NAME)->authenticate($request));
    }

    #[Test]
    public function fallsBackToTheDefaultEnvNameWhenConfigurationThrows(): void
    {
        $_ENV['ROUTING_BEARER_TOKEN'] = 'default-token';

        try {
            $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
            $extensionConfiguration->method('get')->willThrowException(new RuntimeException('not configured'));

            $request = (new ServerRequest('https://example.com/api'))->withHeader('Authorization', 'Bearer default-token');

            self::assertTrue((new BearerTokenAuthenticator($extensionConfiguration))->authenticate($request));
        } finally {
            unset($_ENV['ROUTING_BEARER_TOKEN']);
        }
    }

    private function authenticator(string $configuredEnvName = ''): BearerTokenAuthenticator
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($configuredEnvName);

        return new BearerTokenAuthenticator($extensionConfiguration);
    }
}
