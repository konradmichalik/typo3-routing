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

use KonradMichalik\Typo3Routing\Http\CorsHandler;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\{Response, ServerRequest};

/**
 * CorsHandlerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(CorsHandler::class)]
final class CorsHandlerTest extends TestCase
{
    #[Test]
    public function isDisabledWhenNoOriginsAreConfigured(): void
    {
        $handler = $this->handler([]);

        self::assertFalse($handler->isEnabled());
        // A disabled handler never touches the response.
        $response = $handler->decorate(new Response('php://temp', 200), $this->request('https://app.example.com'));
        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function isEnabledOnceAnOriginIsConfigured(): void
    {
        self::assertTrue($this->handler(['allowedOrigins' => 'https://app.example.com'])->isEnabled());
    }

    #[Test]
    public function staysDisabledWhenExtensionConfigurationThrows(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new RuntimeException('not configured'));

        self::assertFalse((new CorsHandler($extensionConfiguration))->isEnabled());
    }

    #[Test]
    public function decorateEchoesAllowedOriginAndVary(): void
    {
        $handler = $this->handler(['allowedOrigins' => 'https://app.example.com, https://admin.example.com']);

        $response = $handler->decorate(new Response('php://temp', 200), $this->request('https://admin.example.com'));

        self::assertSame('https://admin.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    #[Test]
    public function decorateReturnsWildcardWithoutCredentials(): void
    {
        $handler = $this->handler(['allowedOrigins' => '*']);

        $response = $handler->decorate(new Response('php://temp', 200), $this->request('https://anywhere.example'));

        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('', $response->getHeaderLine('Vary'));
    }

    #[Test]
    public function decorateEchoesConcreteOriginWhenWildcardCombinedWithCredentials(): void
    {
        $handler = $this->handler(['allowedOrigins' => '*', 'allowCredentials' => '1']);

        $response = $handler->decorate(new Response('php://temp', 200), $this->request('https://anywhere.example'));

        self::assertSame('https://anywhere.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    #[Test]
    public function decorateLeavesDisallowedOriginUntouched(): void
    {
        $handler = $this->handler(['allowedOrigins' => 'https://app.example.com']);

        $response = $handler->decorate(new Response('php://temp', 200), $this->request('https://evil.example'));

        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function decorateAddsExposedHeadersWhenConfigured(): void
    {
        $handler = $this->handler(['allowedOrigins' => 'https://app.example.com', 'exposeHeaders' => 'X-Total-Count, Link']);

        $response = $handler->decorate(new Response('php://temp', 200), $this->request('https://app.example.com'));

        self::assertSame('X-Total-Count, Link', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }

    #[Test]
    public function preflightReturnsAllowedMethodsHeadersAndMaxAge(): void
    {
        $handler = $this->handler(['allowedOrigins' => 'https://app.example.com', 'maxAge' => '600']);

        $response = $handler->preflightResponse(['GET', 'POST'], $this->request('https://app.example.com'));

        self::assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('Content-Type, Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('600', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    #[Test]
    public function preflightDoesNotDuplicateOptionsMethod(): void
    {
        $handler = $this->handler(['allowedOrigins' => 'https://app.example.com']);

        $response = $handler->preflightResponse(['GET', 'OPTIONS'], $this->request('https://app.example.com'));

        self::assertSame('GET, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    /**
     * @param array<string, mixed> $cors
     */
    private function handler(array $cors): CorsHandler
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['cors' => $cors]);

        return new CorsHandler($extensionConfiguration);
    }

    private function request(string $origin): ServerRequest
    {
        return (new ServerRequest('https://example.com/api/count', 'GET'))->withHeader('Origin', $origin);
    }
}
