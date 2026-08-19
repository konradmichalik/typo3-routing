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

use DateTimeImmutable;
use KonradMichalik\Ttt\Http\Requests;
use KonradMichalik\Typo3Routing\Http\{DeprecationHeaders, RouteUrlGenerator, SiteBasePathResolver};
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Http\{Response, ServerRequest};
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * DeprecationHeadersTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(DeprecationHeaders::class)]
final class DeprecationHeadersTest extends TestCase
{
    #[Test]
    public function doesNothingWhenNoRouteMatched(): void
    {
        $response = $this->decorator([])->decorate(new Response('php://temp', 200), $this->request(), null);

        self::assertSame('', $response->getHeaderLine('Deprecation'));
    }

    #[Test]
    public function doesNothingForARouteWithoutTheAttribute(): void
    {
        $response = $this->decorator([])->decorate(new Response('php://temp', 200), $this->request(), 'fresh_route');

        self::assertSame('', $response->getHeaderLine('Deprecation'));
        self::assertSame('', $response->getHeaderLine('Sunset'));
        self::assertSame('', $response->getHeaderLine('Link'));
    }

    #[Test]
    public function emitsDeprecationAsAStructuredFieldDateNeverAsAnHttpDate(): void
    {
        $since = (new DateTimeImmutable('2026-03-01T00:00:00Z'))->getTimestamp();
        $response = $this->decorator(['v1' => ['since' => $since, 'sunset' => null, 'successor' => null, 'documentation' => null]])
            ->decorate(new Response('php://temp', 200), $this->request(), 'v1');

        // RFC 9745: an Item Structured Field Date, "@" plus a Unix timestamp — never an HTTP-date,
        // which is the standard implementation mistake early drafts of the RFC invited.
        self::assertSame('@'.$since, $response->getHeaderLine('Deprecation'));
    }

    #[Test]
    public function emitsSunsetAsAnHttpDateInGmt(): void
    {
        $sunset = (new DateTimeImmutable('2026-12-31T23:59:59Z'))->getTimestamp();
        $response = $this->decorator(['v1' => ['since' => 1, 'sunset' => $sunset, 'successor' => null, 'documentation' => null]])
            ->decorate(new Response('php://temp', 200), $this->request(), 'v1');

        self::assertSame('Thu, 31 Dec 2026 23:59:59 GMT', $response->getHeaderLine('Sunset'));
    }

    #[Test]
    public function omitsSunsetWhenNoneWasDeclared(): void
    {
        $response = $this->decorator(['v1' => ['since' => 1, 'sunset' => null, 'successor' => null, 'documentation' => null]])
            ->decorate(new Response('php://temp', 200), $this->request(), 'v1');

        self::assertSame('', $response->getHeaderLine('Sunset'));
    }

    #[Test]
    public function resolvesTheSuccessorLinkThroughTheUrlGenerator(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = ['v2' => ['path' => '/api/v2', 'methods' => ['GET'], 'controller' => 'ctrl::v2', 'env' => null, 'requirements' => []]];
        $response = $this->decorator(['v1' => ['since' => 1, 'sunset' => null, 'successor' => 'v2', 'documentation' => null]], $routes)
            ->decorate(new Response('php://temp', 200), $this->request(), 'v1');

        self::assertSame('</api/v2>; rel="successor-version"', $response->getHeaderLine('Link'));
    }

    #[Test]
    public function includesTheDocumentationLinkWhenGiven(): void
    {
        $response = $this->decorator(['v1' => ['since' => 1, 'sunset' => null, 'successor' => null, 'documentation' => 'https://example.com/migrate']])
            ->decorate(new Response('php://temp', 200), $this->request(), 'v1');

        self::assertSame('<https://example.com/migrate>; rel="deprecation"', $response->getHeaderLine('Link'));
    }

    #[Test]
    public function combinesSuccessorAndDocumentationLinksInOneHeader(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = ['v2' => ['path' => '/api/v2', 'methods' => ['GET'], 'controller' => 'ctrl::v2', 'env' => null, 'requirements' => []]];
        $response = $this->decorator(['v1' => ['since' => 1, 'sunset' => null, 'successor' => 'v2', 'documentation' => 'https://example.com/migrate']], $routes)
            ->decorate(new Response('php://temp', 200), $this->request(), 'v1');

        self::assertSame(
            '</api/v2>; rel="successor-version", <https://example.com/migrate>; rel="deprecation"',
            $response->getHeaderLine('Link'),
        );
    }

    #[Test]
    public function omitsTheLinkHeaderWhenNeitherSuccessorNorDocumentationAreGiven(): void
    {
        $response = $this->decorator(['v1' => ['since' => 1, 'sunset' => null, 'successor' => null, 'documentation' => null]])
            ->decorate(new Response('php://temp', 200), $this->request(), 'v1');

        self::assertSame('', $response->getHeaderLine('Link'));
    }

    /**
     * @param array<string, array{since: int, sunset: int|null, successor: string|null, documentation: string|null}>                               $deprecations
     * @param array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes
     */
    private function decorator(array $deprecations, array $routes = []): DeprecationHeaders
    {
        $registry = new RouteRegistry($routes, new ServiceLocator([]), deprecations: $deprecations);

        return new DeprecationHeaders($registry, new RouteUrlGenerator($registry, new SiteBasePathResolver()));
    }

    private function request(): ServerRequest
    {
        return Requests::get('https://example.com/')
            ->withAttribute('site', new Site('main', 1, ['base' => 'https://example.com/']))
            ->build();
    }
}
