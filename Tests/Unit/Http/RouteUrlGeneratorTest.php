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

use KonradMichalik\Ttt\Http\Requests;
use KonradMichalik\Typo3Routing\Http\{RouteUrlGenerator, SiteBasePathResolver};
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * RouteUrlGeneratorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RouteUrlGenerator::class)]
final class RouteUrlGeneratorTest extends TestCase
{
    #[Test]
    public function generatesUrlIncludingSiteBase(): void
    {
        $request = $this->request('https://example.com/sub/', 'https://example.com/sub/');

        self::assertSame('/sub/api/count', $this->createGenerator()->generate($request, 'fixture_count'));
    }

    #[Test]
    public function generatesUrlWithoutBaseForRootSite(): void
    {
        $request = $this->request('https://example.com/', 'https://example.com/');

        self::assertSame('/api/count', $this->createGenerator()->generate($request, 'fixture_count'));
    }

    #[Test]
    public function substitutesRouteParameters(): void
    {
        $request = $this->request('https://example.com/', 'https://example.com/');

        self::assertSame('/api/item/5', $this->createGenerator()->generate($request, 'fixture_item', ['id' => 5]));
    }

    #[Test]
    public function appendsExtraParametersAsQueryString(): void
    {
        $request = $this->request('https://example.com/', 'https://example.com/');

        self::assertSame('/api/count?foo=bar', $this->createGenerator()->generate($request, 'fixture_count', ['foo' => 'bar']));
    }

    #[Test]
    public function returnsAbsoluteUrlUnprefixedWhenRouteRequiresADifferentScheme(): void
    {
        $request = $this->request('http://example.com/', 'http://example.com/');

        $url = $this->createGenerator()->generate($request, 'fixture_secure_only');

        self::assertStringStartsWith('https://example.com/api/secure-only', $url);
    }

    #[Test]
    public function returnsAbsoluteUrlUnprefixedWhenRouteRequiresADifferentHost(): void
    {
        $request = $this->request('https://example.com/', 'https://example.com/');

        $url = $this->createGenerator()->generate($request, 'fixture_tenant');

        self::assertStringStartsWith('https://api.example.com/api/tenant', $url);
    }

    #[Test]
    public function returnsAbsoluteUrlUnprefixedWhenBothSchemeAndHostDiffer(): void
    {
        // Symfony escalates straight to an absolute URL (scheme mismatch alone already forces this),
        // so a route requiring both a different scheme and a different host still produces one clean
        // "https://…" URL rather than the "//…" network-path shape a host-only mismatch produces.
        $request = $this->request('http://example.com/', 'http://example.com/');

        $url = $this->createGenerator()->generate($request, 'fixture_secure_tenant');

        self::assertStringStartsWith('https://api.example.com/api/secure-tenant', $url);
    }

    private function createGenerator(): RouteUrlGenerator
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, schemes?: list<string>, host?: string|null}> $routes */
        $routes = [
            'fixture_count' => [
                'path' => '/api/count',
                'methods' => ['GET'],
                'controller' => 'fixture::count',
                'env' => null,
                'requirements' => [],
            ],
            'fixture_item' => [
                'path' => '/api/item/{id}',
                'methods' => ['GET'],
                'controller' => 'fixture::item',
                'env' => null,
                'requirements' => [],
            ],
            'fixture_secure_only' => [
                'path' => '/api/secure-only',
                'methods' => ['GET'],
                'controller' => 'fixture::secureOnly',
                'env' => null,
                'requirements' => [],
                'schemes' => ['https'],
            ],
            'fixture_tenant' => [
                'path' => '/api/tenant',
                'methods' => ['GET'],
                'controller' => 'fixture::tenant',
                'env' => null,
                'requirements' => [],
                'host' => 'api.example.com',
            ],
            'fixture_secure_tenant' => [
                'path' => '/api/secure-tenant',
                'methods' => ['GET'],
                'controller' => 'fixture::secureTenant',
                'env' => null,
                'requirements' => [],
                'schemes' => ['https'],
                'host' => 'api.example.com',
            ],
        ];
        $registry = new RouteRegistry($routes, new ServiceLocator([]));

        return new RouteUrlGenerator($registry, new SiteBasePathResolver());
    }

    private function request(string $url, string $base): ServerRequest
    {
        return Requests::get($url)->withAttribute('site', new Site('main', 1, ['base' => $base]))->withoutNormalizedParams()->build();
    }
}
