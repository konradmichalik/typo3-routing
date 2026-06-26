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

namespace KonradMichalik\Typo3Routing\Tests\Functional\Middleware;

use KonradMichalik\Typo3Routing\Http\RouteUrlGenerator;
use KonradMichalik\Typo3Routing\Middleware\RouteDispatcher;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\{Response, ServerRequest};
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * RouteDispatcherTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RouteDispatcherTest extends FunctionalTestCase
{
    protected bool $initializeDatabase = false;

    protected array $testExtensionsToLoad = [
        'typo3_routing',
        __DIR__.'/../Fixtures/Extensions/routing_test',
    ];

    #[Test]
    public function dispatchesMatchingRouteToController(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/count'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    #[Test]
    public function returnsNotFoundForUnknownPathUnderPrefix(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/missing'));

        self::assertSame(404, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"error":"Not Found","status":404}', (string) $response->getBody());
    }

    #[Test]
    public function returnsMethodNotAllowedWithAllowHeader(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/submit'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }

    #[Test]
    public function fallsThroughForPathOutsideThePrefix(): void
    {
        $sentinel = new Response('php://temp', 418);

        $response = $this->process($this->request('GET', 'https://example.com/some/page'), $sentinel);

        self::assertSame($sentinel, $response);
    }

    #[Test]
    public function hidesEnvBoundRouteOutsideItsContext(): void
    {
        // The functional test runs in the "Testing" context, so the Development-bound route is invisible.
        $response = $this->process($this->request('GET', 'https://example.com/api/example/dev'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function passesRouteParametersAsRequestAttributes(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/item/42'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"id":"42"}', (string) $response->getBody());
    }

    #[Test]
    public function rejectsRouteParameterViolatingRequirement(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/item/abc'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returnsBadRequestWhenRequiredInputParameterIsMissing(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/search'));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function returnsBadRequestWhenRequiredInputParameterViolatesFormat(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/search?q=foo'));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function dispatchesWhenRequiredInputParameterMatches(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/search?q=42'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"q":"42"}', (string) $response->getBody());
    }

    #[Test]
    public function stripsSiteBaseBeforeMatching(): void
    {
        $response = $this->process(
            $this->request('GET', 'https://example.com/sub/api/example/count', 'https://example.com/sub/'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    #[Test]
    public function generatesReachableUrlIncludingSiteBase(): void
    {
        $generator = $this->get(RouteUrlGenerator::class);
        $request = $this->request('GET', 'https://example.com/sub/', 'https://example.com/sub/');

        self::assertSame('/sub/api/example/count', $generator->generate($request, 'example_count'));
    }

    private function process(ServerRequestInterface $request, ?ResponseInterface $fallThrough = null): ResponseInterface
    {
        $dispatcher = $this->get(RouteDispatcher::class);

        return $dispatcher->process($request, $this->handler($fallThrough ?? new Response('php://temp', 200)));
    }

    private function request(string $method, string $url, string $base = 'https://example.com/'): ServerRequest
    {
        $site = new Site('main', 1, [
            'base' => $base,
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'English',
                    'locale' => 'en_US.UTF-8',
                    'base' => $base,
                ],
            ],
        ]);

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return (new ServerRequest($url, $method))
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage())
            ->withQueryParams($query);
    }

    private function handler(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
