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

use KonradMichalik\Ttt\Attribute\WithEnvironment;
use KonradMichalik\Ttt\Http\RequestBuilder;
use KonradMichalik\Typo3Routing\Http\{CorsHandler, CorsPreflightResolver};
use KonradMichalik\Typo3Routing\Routing\{RouteMatcher, RouteRegistry, SiteLanguageScope};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RequestContext;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\SiteFinder;

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
    public function answersThePreflightForTheTrailingSlashVariantOfARoute(): void
    {
        $resolver = $this->resolver(['allowedOrigins' => 'https://app.example.com']);

        $response = $resolver->resolve($this->request('OPTIONS', 'GET'), '/api/public/', new RequestContext());

        self::assertNotNull($response);
        self::assertSame('GET, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
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

    #[Test]
    #[WithEnvironment(context: 'Production')]
    public function rejectsAnEnvBoundRouteOutsideItsContextAsNotFound(): void
    {
        $resolver = $this->resolver(['allowedOrigins' => 'https://app.example.com']);

        $this->expectException(ResourceNotFoundException::class);
        $resolver->resolve($this->request('OPTIONS', 'GET'), '/api/dev', new RequestContext());
    }

    #[Test]
    #[WithEnvironment(context: 'Development')]
    public function answersThePreflightForAnEnvBoundRouteInsideItsContext(): void
    {
        $resolver = $this->resolver(['allowedOrigins' => 'https://app.example.com']);

        self::assertNotNull($resolver->resolve($this->request('OPTIONS', 'GET'), '/api/dev', new RequestContext()));
    }

    #[Test]
    public function rejectsARouteBoundToAnotherSiteAsNotFound(): void
    {
        $resolver = $this->resolver(['allowedOrigins' => 'https://app.example.com']);

        $this->expectException(ResourceNotFoundException::class);
        $resolver->resolve($this->request('OPTIONS', 'GET'), '/api/site-scoped', new RequestContext());
    }

    #[Test]
    public function rejectsARouteBoundToAnotherLanguageAsNotFound(): void
    {
        $resolver = $this->resolver(['allowedOrigins' => 'https://app.example.com']);

        $this->expectException(ResourceNotFoundException::class);
        $resolver->resolve($this->request('OPTIONS', 'GET'), '/api/language-scoped', new RequestContext());
    }

    /**
     * @param array<string, mixed> $cors
     */
    private function resolver(array $cors): CorsPreflightResolver
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['cors' => $cors]);

        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, sites?: list<string>, languages?: list<int>}> $routes */
        $routes = [
            'public' => ['path' => '/api/public', 'methods' => ['GET'], 'controller' => 'ctrl::publicAction', 'env' => null, 'requirements' => []],
            'dev' => ['path' => '/api/dev', 'methods' => ['GET'], 'controller' => 'ctrl::dev', 'env' => 'Development', 'requirements' => []],
            'siteScoped' => ['path' => '/api/site-scoped', 'methods' => ['GET'], 'controller' => 'ctrl::publicAction', 'env' => null, 'requirements' => [], 'sites' => ['intranet']],
            'languageScoped' => ['path' => '/api/language-scoped', 'methods' => ['GET'], 'controller' => 'ctrl::publicAction', 'env' => null, 'requirements' => [], 'languages' => [2]],
            'overridden' => ['path' => '/api/overridden', 'methods' => ['POST'], 'controller' => 'ctrl::overridden', 'env' => null, 'requirements' => []],
        ];

        /** @var array<string, array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}> $corsConfigs */
        $corsConfigs = [
            'overridden' => ['allowedOrigins' => ['https://partner.example.org'], 'allowedHeaders' => 'Content-Type, Authorization', 'allowCredentials' => false, 'exposeHeaders' => '', 'maxAge' => 3600],
        ];

        $registry = new RouteRegistry($routes, new ServiceLocator([]), corsConfigs: $corsConfigs);

        return new CorsPreflightResolver(
            $registry,
            new RouteMatcher($registry, $extensionConfiguration),
            new CorsHandler($extensionConfiguration),
            new SiteLanguageScope($this->createMock(SiteFinder::class), $this->createMock(LogManager::class)),
        );
    }

    private function request(string $method, ?string $intendedMethod = null): ServerRequest
    {
        $builder = new RequestBuilder($method, 'https://example.com/api/public');

        if (null !== $intendedMethod) {
            $builder->withHeader('Access-Control-Request-Method', $intendedMethod);
        }

        return $builder->build();
    }
}
