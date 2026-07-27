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

use KonradMichalik\Ttt\Http\RequestBuilder;
use KonradMichalik\Typo3Routing\Http\{CorsHandler, CorsPreflightResolver};
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\RequestContext;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * CorsPreflightResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(CorsPreflightResolver::class)]
final class CorsPreflightResolverTest extends TestCase
{
    #[Test]
    public function returnsNullWhenTheRequestIsNotAPreflight(): void
    {
        $resolver = $this->resolver(['allowedOrigins' => 'https://app.example.com']);

        self::assertNull($resolver->resolve($this->request('GET'), '/api/public', new RequestContext()));
    }

    #[Test]
    public function returnsNullWhenTheAccessControlRequestMethodHeaderIsMissing(): void
    {
        $resolver = $this->resolver(['allowedOrigins' => 'https://app.example.com']);

        self::assertNull($resolver->resolve($this->request('OPTIONS'), '/api/public', new RequestContext()));
    }

    #[Test]
    public function returnsNullForAnUnknownPath(): void
    {
        $resolver = $this->resolver(['allowedOrigins' => 'https://app.example.com']);

        self::assertNull($resolver->resolve($this->request('OPTIONS', 'GET'), '/api/missing', new RequestContext()));
    }

    #[Test]
    public function returnsNullWhenTheIntendedMethodMatchesNoRouteAndGlobalCorsIsOff(): void
    {
        $resolver = $this->resolver([]);

        self::assertNull($resolver->resolve($this->request('OPTIONS', 'DELETE'), '/api/public', new RequestContext()));
    }

    #[Test]
    public function fallsBackToGlobalPolicyWhenTheIntendedMethodMatchesNoSpecificRoute(): void
    {
        $resolver = $this->resolver(['allowedOrigins' => 'https://app.example.com']);

        $response = $resolver->resolve($this->request('OPTIONS', 'DELETE')->withHeader('Origin', 'https://app.example.com'), '/api/public', new RequestContext());

        self::assertNotNull($response);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    #[Test]
    public function returnsNullForAMatchedRouteWithoutAnyCorsConfigured(): void
    {
        $resolver = $this->resolver([]);

        self::assertNull($resolver->resolve($this->request('OPTIONS', 'GET'), '/api/public', new RequestContext()));
    }

    #[Test]
    public function usesTheMatchedRoutesOwnCorsOverrideEvenWhenGlobalCorsIsOff(): void
    {
        $resolver = $this->resolver([]);

        $response = $resolver->resolve(
            $this->request('OPTIONS', 'POST')->withHeader('Origin', 'https://partner.example.org'),
            '/api/overridden',
            new RequestContext(),
        );

        self::assertNotNull($response);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://partner.example.org', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('POST, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    /**
     * @param array<string, mixed> $cors
     */
    private function resolver(array $cors): CorsPreflightResolver
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['cors' => $cors]);

        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'public' => ['path' => '/api/public', 'methods' => ['GET'], 'controller' => 'ctrl::publicAction', 'env' => null, 'requirements' => []],
            'overridden' => ['path' => '/api/overridden', 'methods' => ['POST'], 'controller' => 'ctrl::overridden', 'env' => null, 'requirements' => []],
        ];

        /** @var array<string, array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}> $corsConfigs */
        $corsConfigs = [
            'overridden' => ['allowedOrigins' => ['https://partner.example.org'], 'allowedHeaders' => 'Content-Type, Authorization', 'allowCredentials' => false, 'exposeHeaders' => '', 'maxAge' => 3600],
        ];

        $registry = new RouteRegistry($routes, new ServiceLocator([]), corsConfigs: $corsConfigs);

        return new CorsPreflightResolver($registry, new CorsHandler($extensionConfiguration));
    }

    private function request(string $method, ?string $intendedMethod = null): ServerRequest
    {
        $builder = new RequestBuilder($method, 'https://example.com/api/public');

        if (null !== $intendedMethod) {
            $builder->withHeader('Access-Control-Request-Method', $intendedMethod);
        }

        return $builder->withoutNormalizedParams()->build();
    }
}
