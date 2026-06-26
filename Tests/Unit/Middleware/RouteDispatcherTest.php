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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Middleware;

use KonradMichalik\RoutingTest\Controller\ExampleController;
use KonradMichalik\Typo3Routing\Cache\ResponseCacheManager;
use KonradMichalik\Typo3Routing\Http\SiteBasePathResolver;
use KonradMichalik\Typo3Routing\Middleware\RouteDispatcher;
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\CreatesResponseCacheManager;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\{ApplicationContext, Environment};
use TYPO3\CMS\Core\Http\{Response, ServerRequest};
use TYPO3\CMS\Core\Site\Entity\Site;

use function dirname;

/**
 * RouteDispatcherTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RouteDispatcher::class)]
final class RouteDispatcherTest extends TestCase
{
    use CreatesResponseCacheManager;

    private ResponseCacheManager $responseCache;

    protected function setUp(): void
    {
        $this->responseCache = $this->createResponseCacheManager();
    }

    #[Test]
    public function dispatchesMatchingRouteToController(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/count'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    #[Test]
    public function returnsNotFoundForUnknownPathUnderPrefix(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/missing'));

        self::assertSame(404, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"error":"Not Found","status":404}', (string) $response->getBody());
    }

    #[Test]
    public function returnsMethodNotAllowedWithAllowHeader(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/submit'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }

    #[Test]
    public function fallsThroughForPathOutsideThePrefix(): void
    {
        $sentinel = new Response('php://temp', 418);

        $response = $this->dispatch($this->request('GET', 'https://example.com/some/page'), $sentinel);

        self::assertSame($sentinel, $response);
    }

    #[Test]
    public function passesRouteParametersAsRequestAttributes(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/item/7'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"id":"7"}', (string) $response->getBody());
    }

    #[Test]
    public function returnsNotFoundWhenRouteParameterViolatesRequirement(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/item/abc'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returnsBadRequestWhenRequiredInputParameterIsMissing(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/guarded'));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Missing required parameter: q', (string) $response->getBody());
    }

    #[Test]
    public function returnsBadRequestWhenRequiredInputParameterViolatesFormat(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/guarded?q=foo'));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Invalid value for parameter: q', (string) $response->getBody());
    }

    #[Test]
    public function dispatchesWhenRequiredInputParameterMatches(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/guarded?q=42'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function validatesRequiredParameterFromPostBody(): void
    {
        $request = $this->request('POST', 'https://example.com/api/posted')->withParsedBody(['n' => '7']);

        $response = $this->dispatch($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function stripsSiteBaseBeforeMatching(): void
    {
        $response = $this->dispatch(
            $this->request('GET', 'https://example.com/sub/api/count', 'https://example.com/sub/'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    #[Test]
    public function hidesEnvBoundRouteOutsideItsContext(): void
    {
        $root = dirname(__DIR__, 3);
        Environment::initialize(
            new ApplicationContext('Production'),
            true,
            false,
            $root,
            $root,
            $root.'/var',
            $root.'/config',
            __FILE__,
            'UNIX',
        );

        $response = $this->dispatch($this->request('GET', 'https://example.com/api/dev'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function fallsBackToDefaultPrefixWhenExtensionConfigurationThrows(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new RuntimeException('not configured'));

        $dispatcher = new RouteDispatcher($this->registry(), new SiteBasePathResolver(), $this->responseCache, $extensionConfiguration);
        $response = $dispatcher->process(
            $this->request('GET', 'https://example.com/api/count'),
            $this->handler(new Response('php://temp', 200)),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function cachesGetResponseAndServesItOnRepeatRequest(): void
    {
        $first = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));
        $second = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));

        self::assertSame(200, $first->getStatusCode());
        // The controller returns a fresh random token each call — identical bodies prove a cache hit.
        self::assertSame((string) $first->getBody(), (string) $second->getBody());
    }

    #[Test]
    public function regeneratesCachedResponseAfterTagFlush(): void
    {
        $first = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));
        $this->responseCache->flushByTag('pages');
        $second = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));

        self::assertNotSame((string) $first->getBody(), (string) $second->getBody());
    }

    private function dispatch(ServerRequestInterface $request, ?ResponseInterface $fallThrough = null): ResponseInterface
    {
        return $this->dispatcher()->process($request, $this->handler($fallThrough ?? new Response('php://temp', 200)));
    }

    private function dispatcher(): RouteDispatcher
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn('/api/');

        return new RouteDispatcher($this->registry(), new SiteBasePathResolver(), $this->responseCache, $extensionConfiguration);
    }

    private function registry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'submit' => ['path' => '/api/submit', 'methods' => ['POST'], 'controller' => 'ctrl::submit', 'env' => null, 'requirements' => []],
            'item' => ['path' => '/api/item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::item', 'env' => null, 'requirements' => ['id' => '\d+']],
            'dev' => ['path' => '/api/dev', 'methods' => ['GET'], 'controller' => 'ctrl::dev', 'env' => 'Development', 'requirements' => []],
            'cached' => ['path' => '/api/cached', 'methods' => ['GET'], 'controller' => 'ctrl::cached', 'env' => null, 'requirements' => []],
            'guarded' => ['path' => '/api/guarded', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => ['q' => '\d+']],
            'posted' => ['path' => '/api/posted', 'methods' => ['POST'], 'controller' => 'ctrl::submit', 'env' => null, 'requirements' => ['n' => '\d+']],
        ];

        /** @var array<string, array{lifetime: int, tags: list<string>, ignoreParams: list<string>}> $cacheConfigs */
        $cacheConfigs = [
            'cached' => ['lifetime' => 3600, 'tags' => ['pages'], 'ignoreParams' => []],
        ];

        $locator = new ServiceLocator(['ctrl' => static fn (): ExampleController => new ExampleController()]);

        return new RouteRegistry($routes, $locator, $cacheConfigs);
    }

    private function request(string $method, string $url, string $base = 'https://example.com/'): ServerRequest
    {
        $site = new Site('main', 1, [
            'base' => $base,
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => $base],
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
