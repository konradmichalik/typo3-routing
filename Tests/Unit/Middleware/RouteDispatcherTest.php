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
use KonradMichalik\Typo3Routing\Authentication\AccessGuard;
use KonradMichalik\Typo3Routing\Cache\ResponseCacheManager;
use KonradMichalik\Typo3Routing\Http\{CorsHandler, SiteBasePathResolver};
use KonradMichalik\Typo3Routing\Middleware\RouteDispatcher;
use KonradMichalik\Typo3Routing\RateLimit\RateLimitEnforcer;
use KonradMichalik\Typo3Routing\Routing\{ControllerArgumentResolver, RouteRegistry};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Authentication\{DenyAuthenticator, PassAuthenticator};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\CreatesResponseCacheManager;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\{ApplicationContext, Environment};
use TYPO3\CMS\Core\Http\{NormalizedParams, Response, ServerRequest};
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

    private RateLimitEnforcer $rateLimiter;

    protected function setUp(): void
    {
        $this->responseCache = $this->createResponseCacheManager();
        $this->rateLimiter = new RateLimitEnforcer(new InMemoryStorage());
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
        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Not Found","status":404}', (string) $response->getBody());
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
    public function matchesPathUnderAnyCommaSeparatedPrefix(): void
    {
        $dispatcher = $this->dispatcherWithPrefix('/api/, /va/');

        $response = $dispatcher->process(
            $this->request('GET', 'https://example.com/va/count'),
            $this->handler(new Response('php://temp', 200)),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    #[Test]
    public function fallsThroughForPathOutsideEveryCommaSeparatedPrefix(): void
    {
        $sentinel = new Response('php://temp', 418);
        $dispatcher = $this->dispatcherWithPrefix('/api/, /va/');

        $response = $dispatcher->process($this->request('GET', 'https://example.com/some/page'), $this->handler($sentinel));

        self::assertSame($sentinel, $response);
    }

    #[Test]
    public function dispatchesMatchingRouteWithNoPrefixConfigured(): void
    {
        $dispatcher = $this->dispatcherWithPrefix('');

        $response = $dispatcher->process(
            $this->request('GET', 'https://example.com/api/count'),
            $this->handler(new Response('php://temp', 200)),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    #[Test]
    public function fallsThroughInsteadOfNotFoundWhenNoPrefixIsConfiguredAndNothingMatches(): void
    {
        $sentinel = new Response('php://temp', 418);
        $dispatcher = $this->dispatcherWithPrefix('');

        $response = $dispatcher->process($this->request('GET', 'https://example.com/some/page'), $this->handler($sentinel));

        self::assertSame($sentinel, $response);
    }

    #[Test]
    public function stillReturnsMethodNotAllowedWhenNoPrefixIsConfigured(): void
    {
        $dispatcher = $this->dispatcherWithPrefix('');

        $response = $dispatcher->process(
            $this->request('GET', 'https://example.com/api/submit'),
            $this->handler(new Response('php://temp', 200)),
        );

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }

    #[Test]
    public function passesPathPlaceholderAsTypedControllerArgument(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/item/7'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"id":7}', (string) $response->getBody());
    }

    #[Test]
    public function returnsBadRequestWhenTypedArgumentCannotBeCoerced(): void
    {
        // No requirement on this route, so matching succeeds and the resolver's coercion rejects it.
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/typed/abc'));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Invalid value for parameter: id', (string) $response->getBody());
    }

    #[Test]
    public function usesNormalizedParamsRemoteAddressForRateLimiting(): void
    {
        $normalizedParams = $this->createMock(NormalizedParams::class);
        $normalizedParams->method('getRemoteAddress')->willReturn('203.0.113.5');
        $request = $this->request('GET', 'https://example.com/api/limited')->withAttribute('normalizedParams', $normalizedParams);

        $response = $this->dispatch($request);

        self::assertSame(200, $response->getStatusCode());
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

        $registry = $this->registry();
        $dispatcher = new RouteDispatcher($registry, new SiteBasePathResolver(), $this->responseCache, $this->rateLimiter, new ControllerArgumentResolver(), new AccessGuard($registry, new Context()), new CorsHandler($extensionConfiguration), $extensionConfiguration);
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

    #[Test]
    public function attachesAnETagToCachedGetResponses(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('"'.hash('sha256', (string) $response->getBody()).'"', $response->getHeaderLine('ETag'));
    }

    #[Test]
    public function returnsNotModifiedWhenIfNoneMatchMatchesTheETag(): void
    {
        $first = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));
        $etag = $first->getHeaderLine('ETag');

        $second = $this->dispatch(
            $this->request('GET', 'https://example.com/api/cached')->withHeader('If-None-Match', $etag),
        );

        self::assertSame(304, $second->getStatusCode());
        self::assertSame($etag, $second->getHeaderLine('ETag'));
        self::assertSame('', (string) $second->getBody());
    }

    #[Test]
    public function servesTheFullResponseWhenIfNoneMatchDoesNotMatch(): void
    {
        $this->dispatch($this->request('GET', 'https://example.com/api/cached'));

        $response = $this->dispatch(
            $this->request('GET', 'https://example.com/api/cached')->withHeader('If-None-Match', '"stale"'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame('', (string) $response->getBody());
    }

    #[Test]
    public function blocksRequestsExceedingTheRateLimitWith429AndRetryAfter(): void
    {
        $first = $this->dispatch($this->request('GET', 'https://example.com/api/limited'));
        $second = $this->dispatch($this->request('GET', 'https://example.com/api/limited'));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Too Many Requests","status":429}', (string) $second->getBody());
        self::assertNotSame('', $second->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function doesNotRateLimitRoutesWithoutAnAttribute(): void
    {
        $first = $this->dispatch($this->request('GET', 'https://example.com/api/count'));
        $second = $this->dispatch($this->request('GET', 'https://example.com/api/count'));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
    }

    #[Test]
    public function dispatchesPublicRouteWithoutAuthenticator(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/count'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function surfacesTheAccessGuardDenial(): void
    {
        // The guard's full auth/CSRF matrix lives in AccessGuardTest; here we only prove the dispatcher
        // returns the guard's response instead of dispatching the controller.
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/denied'));

        self::assertSame(401, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Unauthorized","status":401}', (string) $response->getBody());
    }

    #[Test]
    public function neverCachesAuthenticatedRoutesDespiteCacheAttribute(): void
    {
        $first = $this->dispatch($this->request('GET', 'https://example.com/api/securecached'));
        $second = $this->dispatch($this->request('GET', 'https://example.com/api/securecached'));

        self::assertSame(200, $first->getStatusCode());
        // The controller returns a fresh random token each call; differing bodies prove the response was not cached.
        self::assertNotSame((string) $first->getBody(), (string) $second->getBody());
    }

    #[Test]
    public function addsNoCorsHeadersWhenCorsIsDisabled(): void
    {
        $request = $this->request('GET', 'https://example.com/api/count')->withHeader('Origin', 'https://app.example.com');

        $response = $this->dispatch($request);

        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function decoratesResponseWithAllowOriginForAllowedOrigin(): void
    {
        $dispatcher = $this->dispatcherWithCors(['allowedOrigins' => 'https://app.example.com']);
        $request = $this->request('GET', 'https://example.com/api/count')->withHeader('Origin', 'https://app.example.com');

        $response = $dispatcher->process($request, $this->handler(new Response('php://temp', 200)));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    #[Test]
    public function echoesWildcardOriginWhenConfigured(): void
    {
        $dispatcher = $this->dispatcherWithCors(['allowedOrigins' => '*']);
        $request = $this->request('GET', 'https://example.com/api/count')->withHeader('Origin', 'https://anywhere.example');

        $response = $dispatcher->process($request, $this->handler(new Response('php://temp', 200)));

        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function omitsAllowOriginForDisallowedOrigin(): void
    {
        $dispatcher = $this->dispatcherWithCors(['allowedOrigins' => 'https://app.example.com']);
        $request = $this->request('GET', 'https://example.com/api/count')->withHeader('Origin', 'https://evil.example');

        $response = $dispatcher->process($request, $this->handler(new Response('php://temp', 200)));

        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function answersPreflightWith204AndCorsHeaders(): void
    {
        $dispatcher = $this->dispatcherWithCors(['allowedOrigins' => 'https://app.example.com']);
        $request = $this->request('OPTIONS', 'https://example.com/api/submit')
            ->withHeader('Origin', 'https://app.example.com')
            ->withHeader('Access-Control-Request-Method', 'POST');

        $response = $dispatcher->process($request, $this->handler(new Response('php://temp', 200)));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        // The route allows POST; OPTIONS is always added for the preflight itself.
        self::assertSame('POST, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertNotSame('', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    #[Test]
    public function answersPreflightForRouteThatExplicitlyAllowsOptions(): void
    {
        $dispatcher = $this->dispatcherWithCors(['allowedOrigins' => 'https://app.example.com']);
        $request = $this->request('OPTIONS', 'https://example.com/api/optionated')
            ->withHeader('Origin', 'https://app.example.com')
            ->withHeader('Access-Control-Request-Method', 'GET');

        $response = $dispatcher->process($request, $this->handler(new Response('php://temp', 200)));

        // The route lists OPTIONS, so the matcher succeeds and the methods come from the route itself.
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('GET, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    #[Test]
    public function preflightForUnknownPathFallsThroughToNotFound(): void
    {
        $dispatcher = $this->dispatcherWithCors(['allowedOrigins' => 'https://app.example.com']);
        $request = $this->request('OPTIONS', 'https://example.com/api/missing')
            ->withHeader('Origin', 'https://app.example.com')
            ->withHeader('Access-Control-Request-Method', 'GET');

        $response = $dispatcher->process($request, $this->handler(new Response('php://temp', 200)));

        self::assertSame(404, $response->getStatusCode());
    }

    private function dispatch(ServerRequestInterface $request, ?ResponseInterface $fallThrough = null, ?Context $context = null): ResponseInterface
    {
        return $this->dispatcher($context)->process($request, $this->handler($fallThrough ?? new Response('php://temp', 200)));
    }

    private function dispatcher(?Context $context = null): RouteDispatcher
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn('/api/');

        return $this->dispatcherWith(new CorsHandler($extensionConfiguration), $extensionConfiguration, $context);
    }

    private function dispatcherWithPrefix(string $prefix): RouteDispatcher
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($prefix);

        return $this->dispatcherWith(new CorsHandler($extensionConfiguration), $extensionConfiguration);
    }

    /**
     * @param array<string, mixed> $cors
     */
    private function dispatcherWithCors(array $cors, ?Context $context = null): RouteDispatcher
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        // The path-less get() feeds CorsHandler the full config; the get(..., 'prefix') call resolves the prefix.
        $extensionConfiguration->method('get')->willReturnCallback(
            static fn (string $extension, string $path = ''): mixed => '' === $path ? ['cors' => $cors] : '/api/',
        );

        return $this->dispatcherWith(new CorsHandler($extensionConfiguration), $extensionConfiguration, $context);
    }

    private function dispatcherWith(CorsHandler $cors, ExtensionConfiguration $extensionConfiguration, ?Context $context = null): RouteDispatcher
    {
        $registry = $this->registry();
        $accessGuard = new AccessGuard($registry, $context ?? new Context());

        return new RouteDispatcher($registry, new SiteBasePathResolver(), $this->responseCache, $this->rateLimiter, new ControllerArgumentResolver(), $accessGuard, $cors, $extensionConfiguration);
    }

    private function registry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'vaCount' => ['path' => '/va/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'submit' => ['path' => '/api/submit', 'methods' => ['POST'], 'controller' => 'ctrl::submit', 'env' => null, 'requirements' => []],
            'item' => ['path' => '/api/item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::item', 'env' => null, 'requirements' => ['id' => '\d+']],
            'typed' => ['path' => '/api/typed/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::item', 'env' => null, 'requirements' => []],
            'dev' => ['path' => '/api/dev', 'methods' => ['GET'], 'controller' => 'ctrl::dev', 'env' => 'Development', 'requirements' => []],
            'cached' => ['path' => '/api/cached', 'methods' => ['GET'], 'controller' => 'ctrl::cached', 'env' => null, 'requirements' => []],
            'guarded' => ['path' => '/api/guarded', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => ['q' => '\d+']],
            'posted' => ['path' => '/api/posted', 'methods' => ['POST'], 'controller' => 'ctrl::submit', 'env' => null, 'requirements' => ['n' => '\d+']],
            'limited' => ['path' => '/api/limited', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'denied' => ['path' => '/api/denied', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'securecached' => ['path' => '/api/securecached', 'methods' => ['GET'], 'controller' => 'ctrl::cached', 'env' => null, 'requirements' => []],
            'optionated' => ['path' => '/api/optionated', 'methods' => ['GET', 'OPTIONS'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
        ];

        /** @var array<string, array{lifetime: int, tags: list<string>, ignoreParams: list<string>}> $cacheConfigs */
        $cacheConfigs = [
            'cached' => ['lifetime' => 3600, 'tags' => ['pages'], 'ignoreParams' => []],
            // Combined with an authenticator below — the dispatcher must force no-store regardless.
            'securecached' => ['lifetime' => 3600, 'tags' => ['pages'], 'ignoreParams' => []],
        ];

        /** @var array<string, array{limit: int, interval: string, policy: string}> $rateLimits */
        $rateLimits = [
            'limited' => ['limit' => 1, 'interval' => '1 minute', 'policy' => 'sliding_window'],
        ];

        $request = ['name' => 'request', 'type' => null, 'source' => 'request', 'nullable' => false, 'hasDefault' => false, 'default' => null];
        $id = ['name' => 'id', 'type' => 'int', 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null];

        /** @var array<string, list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>> $arguments */
        $arguments = [
            'count' => [],
            'vaCount' => [],
            'submit' => [$request],
            'item' => [$id],
            'typed' => [$id],
            'dev' => [],
            'cached' => [],
            'guarded' => [],
            'posted' => [$request],
            'limited' => [],
            'denied' => [],
            'securecached' => [],
            'optionated' => [],
        ];

        /** @var array<string, list<array{service: string, options: array<string, mixed>}>> $authenticators */
        $authenticators = [
            'denied' => [['service' => DenyAuthenticator::class, 'options' => []]],
            'securecached' => [['service' => PassAuthenticator::class, 'options' => []]],
        ];

        /** @var array<string, string> $requestTokenScopes */
        $requestTokenScopes = [];

        $locator = new ServiceLocator(['ctrl' => static fn (): ExampleController => new ExampleController()]);
        $authenticatorLocator = new ServiceLocator([
            PassAuthenticator::class => static fn (): PassAuthenticator => new PassAuthenticator(),
            DenyAuthenticator::class => static fn (): DenyAuthenticator => new DenyAuthenticator(),
        ]);

        return new RouteRegistry($routes, $locator, $cacheConfigs, $rateLimits, $arguments, $authenticators, $requestTokenScopes, $authenticatorLocator);
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
