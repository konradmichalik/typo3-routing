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
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
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

    #[Test]
    public function generatesUrlThroughAnAlias(): void
    {
        $request = $this->request('https://example.com/', 'https://example.com/');

        self::assertSame('/api/count', $this->createGenerator(['legacy_count' => 'fixture_count'])->generate($request, 'legacy_count'));
    }

    #[Test]
    public function generatesAnAbsoluteUrlWhenRequested(): void
    {
        $request = $this->request('https://example.com/sub/', 'https://example.com/sub/');

        self::assertSame('https://example.com/sub/api/count', $this->createGenerator()->generate($request, 'fixture_count', [], true));
    }

    #[Test]
    public function keepsAnExplicitPortInAnAbsoluteUrl(): void
    {
        $request = $this->request('https://example.com:8443/', 'https://example.com:8443/');

        self::assertSame('https://example.com:8443/api/count', $this->createGenerator()->generate($request, 'fixture_count', [], true));
    }

    /**
     * Every combination of relative/absolute x matching/mismatching host x matching/mismatching scheme
     * x a site base with and without a path segment. The site base always has to end up inside the URL,
     * which is why it is handed to Symfony as the request context's base URL rather than prepended
     * afterwards — an absolute URL cannot be prefixed after the fact.
     *
     * @return iterable<string, array{string, bool, string, string}>
     */
    public static function urlMatrixProvider(): iterable
    {
        $matrix = [
            'matching host, matching scheme' => ['matrix_match_match', '{base}/api/matrix/match-match', 'https://example.com{base}/api/matrix/match-match'],
            'matching host, mismatching scheme' => ['matrix_match_mismatch', 'http://example.com{base}/api/matrix/match-mismatch', 'http://example.com{base}/api/matrix/match-mismatch'],
            'mismatching host, matching scheme' => ['matrix_mismatch_match', 'https://api.example.com{base}/api/matrix/mismatch-match', 'https://api.example.com{base}/api/matrix/mismatch-match'],
            'mismatching host, mismatching scheme' => ['matrix_mismatch_mismatch', 'http://api.example.com{base}/api/matrix/mismatch-mismatch', 'http://api.example.com{base}/api/matrix/mismatch-mismatch'],
        ];

        foreach ($matrix as $label => [$route, $relative, $absolute]) {
            foreach (['root base' => '', 'base with a path' => '/sub'] as $baseLabel => $basePath) {
                yield $label.', relative, '.$baseLabel => [$route, false, $basePath, str_replace('{base}', $basePath, $relative)];
                yield $label.', absolute, '.$baseLabel => [$route, true, $basePath, str_replace('{base}', $basePath, $absolute)];
            }
        }
    }

    #[Test]
    #[DataProvider('urlMatrixProvider')]
    public function generatesTheExpectedUrlFromARequest(string $route, bool $absolute, string $basePath, string $expected): void
    {
        $base = 'https://example.com'.$basePath.'/';

        self::assertSame($expected, $this->createGenerator()->generate($this->request($base, $base), $route, [], $absolute));
    }

    #[Test]
    #[DataProvider('urlMatrixProvider')]
    public function generatesTheExpectedUrlFromASiteWithoutARequest(string $route, bool $absolute, string $basePath, string $expected): void
    {
        $site = new Site('main', 1, ['base' => 'https://example.com'.$basePath.'/']);

        self::assertSame($expected, $this->createGenerator()->generateForSite($site, $route, [], $absolute));
    }

    #[Test]
    public function generatesUrlFromASiteWithoutARequest(): void
    {
        $site = new Site('main', 1, ['base' => 'https://example.com/sub/']);

        self::assertSame('/sub/api/item/5', $this->createGenerator()->generateForSite($site, 'fixture_item', ['id' => 5]));
    }

    #[Test]
    public function generatesAbsoluteUrlFromASiteUsingItsConfiguredScheme(): void
    {
        $site = new Site('main', 1, ['base' => 'http://example.com:8080/sub/']);

        self::assertSame('http://example.com:8080/sub/api/count', $this->createGenerator()->generateForSite($site, 'fixture_count', [], true));
    }

    #[Test]
    public function generatesUrlFromASiteLanguageBaseWhenALanguageIsGiven(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => 'https://example.com/'],
                ['languageId' => 1, 'title' => 'German', 'locale' => 'de_DE.UTF-8', 'base' => 'https://example.de/de/'],
            ],
        ]);

        $generator = $this->createGenerator();

        self::assertSame('/de/api/count', $generator->generateForSite($site, 'fixture_count', language: $site->getLanguageById(1)));
        self::assertSame('https://example.de/de/api/count', $generator->generateForSite($site, 'fixture_count', absolute: true, language: $site->getLanguageById(1)));
    }

    #[Test]
    public function generatesUrlFromASiteWithARelativeBase(): void
    {
        // A site base may be configured as a bare path ("/"), which carries neither scheme nor host.
        // Nothing can be made absolute from that, so the path is all the site base can promise.
        $site = new Site('main', 1, ['base' => '/']);

        self::assertSame('/api/count', $this->createGenerator()->generateForSite($site, 'fixture_count', [], true));
    }

    /**
     * @param array<string, string> $aliases
     */
    private function createGenerator(array $aliases = []): RouteUrlGenerator
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
            'matrix_match_match' => [
                'path' => '/api/matrix/match-match',
                'methods' => ['GET'],
                'controller' => 'fixture::matrix',
                'env' => null,
                'requirements' => [],
                'schemes' => ['https'],
                'host' => 'example.com',
            ],
            'matrix_match_mismatch' => [
                'path' => '/api/matrix/match-mismatch',
                'methods' => ['GET'],
                'controller' => 'fixture::matrix',
                'env' => null,
                'requirements' => [],
                'schemes' => ['http'],
                'host' => 'example.com',
            ],
            'matrix_mismatch_match' => [
                'path' => '/api/matrix/mismatch-match',
                'methods' => ['GET'],
                'controller' => 'fixture::matrix',
                'env' => null,
                'requirements' => [],
                'schemes' => ['https'],
                'host' => 'api.example.com',
            ],
            'matrix_mismatch_mismatch' => [
                'path' => '/api/matrix/mismatch-mismatch',
                'methods' => ['GET'],
                'controller' => 'fixture::matrix',
                'env' => null,
                'requirements' => [],
                'schemes' => ['http'],
                'host' => 'api.example.com',
            ],
        ];
        $registry = new RouteRegistry($routes, new ServiceLocator([]), aliases: $aliases);

        return new RouteUrlGenerator($registry, new SiteBasePathResolver());
    }

    private function request(string $url, string $base): ServerRequest
    {
        return Requests::get($url)->withAttribute('site', new Site('main', 1, ['base' => $base]))->build();
    }
}
