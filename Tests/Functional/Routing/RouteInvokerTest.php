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

namespace KonradMichalik\Typo3Routing\Tests\Functional\Routing;

use KonradMichalik\Ttt\Assertion\JsonAssertions;
use KonradMichalik\Ttt\Http\RequestBuilder;
use KonradMichalik\Ttt\Traits\EnvVarSandbox;
use KonradMichalik\Typo3Routing\Middleware\RouteDispatcher;
use KonradMichalik\Typo3Routing\Routing\RouteInvoker;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Http\{Response, ServerRequest};
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * RouteInvokerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RouteInvokerTest extends FunctionalTestCase
{
    use EnvVarSandbox;
    use JsonAssertions;

    protected bool $initializeDatabase = false;

    protected array $testExtensionsToLoad = [
        'typo3_routing',
        __DIR__.'/../Fixtures/Extensions/routing_test',
    ];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'typo3_routing_ratelimit' => [
                        'frontend' => VariableFrontend::class,
                        'backend' => TransientMemoryBackend::class,
                    ],
                    'typo3_routing' => [
                        'frontend' => VariableFrontend::class,
                        'backend' => TransientMemoryBackend::class,
                    ],
                ],
            ],
        ],
    ];

    protected function tearDown(): void
    {
        $this->restoreEnvVars();
        parent::tearDown();
    }

    #[Test]
    public function isPubliclyAvailableFromTheContainer(): void
    {
        self::assertInstanceOf(RouteInvoker::class, $this->getContainer()->get(RouteInvoker::class));
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function agreeingRoutes(): iterable
    {
        yield 'no arguments' => ['example_count', [], '/api/example/count'];
        yield 'path placeholder' => ['example_item', ['id' => 42], '/api/example/item/42'];
        yield 'omitted optional placeholder' => ['example_blog', [], '/api/example/blog'];
        yield 'query argument' => ['example_search', ['q' => 7], '/api/example/search?q=7'];
        yield 'mixed defaults' => ['example_range', ['from' => 2], '/api/example/range?from=2'];
        yield 'variadic' => ['example_tags', ['tags' => ['a', 'b']], '/api/example/tags?tags[]=a&tags[]=b'];
        yield 'aliased argument' => ['example_aliased', ['q' => 'chair'], '/api/example/aliased?q=chair'];
        yield 'backed enum' => ['example_status', ['status' => 'draft'], '/api/example/status/draft'];
        yield 'unresolvable argument' => ['example_range', [], '/api/example/range'];
        yield 'violated query requirement' => ['example_search', ['q' => 'abc'], '/api/example/search?q=abc'];
        yield 'controller problem' => ['example_problem', [], '/api/example/problem'];
        yield 'env-bound route' => ['example_dev', [], '/api/example/dev'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('agreeingRoutes')]
    public function answersLikeAnHttpCallForTheSameRouteAndInput(string $routeName, array $input, string $url): void
    {
        $overHttp = $this->process($this->request('GET', 'https://example.com'.$url));
        $invoked = $this->get(RouteInvoker::class)->invoke($routeName, $input, $this->request('GET', 'https://example.com/mcp'));

        self::assertSame($overHttp->getStatusCode(), $invoked->getStatusCode());
        self::assertJsonStringEqualsJsonString((string) $overHttp->getBody(), (string) $invoked->getBody());
    }

    /**
     * The one place where a rejected input cannot answer alike: a violated path requirement matches no
     * route at all, and with no `exclusivePrefixes` configured such a path is not claimed by this
     * middleware — the page router takes it. Invoking by name has no such alternative, so the route's
     * own "no resource for this value" answer stands.
     */
    #[Test]
    public function answersNotFoundWhereAnHttpCallLetsAViolatedPathRequirementFallThrough(): void
    {
        $overHttp = $this->process($this->request('GET', 'https://example.com/api/example/item/abc'), 418);
        $invoked = $this->get(RouteInvoker::class)->invoke('example_item', ['id' => 'abc'], $this->request('GET', 'https://example.com/mcp'));

        self::assertSame(418, $overHttp->getStatusCode(), 'the dispatcher should have fallen through to the page handler');
        self::assertSame(404, $invoked->getStatusCode());
    }

    #[Test]
    public function invokesAPostRouteWithAJsonBodyLikeAnHttpCall(): void
    {
        $overHttp = $this->process(
            (new RequestBuilder('POST', 'https://example.com/api/example/json'))
                ->withAttribute('site', $this->site())
                ->withAttribute('language', $this->site()->getDefaultLanguage())
                ->withJsonBody(['title' => 'Chair', 'priority' => 3])
                ->build(),
        );

        $invoked = $this->get(RouteInvoker::class)->invoke('example_json', ['title' => 'Chair', 'priority' => 3], $this->request('GET', 'https://example.com/mcp'));

        self::assertSame(200, $overHttp->getStatusCode());
        self::assertJsonStringEqualsJsonString((string) $overHttp->getBody(), (string) $invoked->getBody());
    }

    #[Test]
    public function enforcesAuthenticationWithTheCallingRequestsToken(): void
    {
        $this->setEnvVar('ROUTING_TEST_TOKEN', 's3cret');

        $withToken = $this->get(RouteInvoker::class)->invoke(
            'example_secure',
            [],
            $this->request('GET', 'https://example.com/mcp')->withHeader('Authorization', 'Bearer s3cret'),
        );
        $withoutToken = $this->get(RouteInvoker::class)->invoke('example_secure', [], $this->request('GET', 'https://example.com/mcp'));

        self::assertSame(200, $withToken->getStatusCode());
        self::assertSame(401, $withoutToken->getStatusCode());
    }

    #[Test]
    public function invokesARequestTokenProtectedRouteAnHttpCallWouldReject(): void
    {
        $overHttp = $this->process($this->request('POST', 'https://example.com/api/example/token'));
        $invoked = $this->get(RouteInvoker::class)->invoke('example_token', [], $this->request('GET', 'https://example.com/mcp'));

        self::assertSame(403, $overHttp->getStatusCode());
        self::assertSame(200, $invoked->getStatusCode());
    }

    #[Test]
    public function ignoresTheRateLimitAnHttpCallEnforces(): void
    {
        $invoker = $this->get(RouteInvoker::class);
        $context = $this->request('GET', 'https://example.com/mcp');

        $invoker->invoke('example_limited', [], $context);
        $invoked = $invoker->invoke('example_limited', [], $context);
        // The very same route rejects the second inbound request — one per minute.
        $this->process($this->request('GET', 'https://example.com/api/example/limited'));
        $overHttp = $this->process($this->request('GET', 'https://example.com/api/example/limited'));

        self::assertSame(200, $invoked->getStatusCode());
        self::assertSame(429, $overHttp->getStatusCode());
    }

    #[Test]
    public function servesNoCachedResponse(): void
    {
        $invoker = $this->get(RouteInvoker::class);
        $context = $this->request('GET', 'https://example.com/mcp');

        $first = (string) $invoker->invoke('example_cached', [], $context)->getBody();
        $second = (string) $invoker->invoke('example_cached', [], $context)->getBody();

        // The route is cached over HTTP; the controller mints a fresh token per call.
        self::assertNotSame($first, $second);
    }

    private function process(ServerRequestInterface $request, int $fallThroughStatus = 200): ResponseInterface
    {
        return $this->get(RouteDispatcher::class)->process($request, $this->handler($fallThroughStatus));
    }

    private function handler(int $status): RequestHandlerInterface
    {
        return new class($status) implements RequestHandlerInterface {
            public function __construct(private readonly int $status) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('php://temp', $this->status);
            }
        };
    }

    private function site(): Site
    {
        return new Site('main', 1, [
            'base' => 'https://example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => 'https://example.com/'],
            ],
        ]);
    }

    private function request(string $method, string $url): ServerRequest
    {
        $site = $this->site();

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        return (new RequestBuilder($method, $url))
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage())
            ->withQueryParams($query) // @phpstan-ignore argument.type
            ->build();
    }
}
