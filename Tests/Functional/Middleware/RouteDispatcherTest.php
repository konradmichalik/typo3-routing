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
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Context\{Context, SecurityAspect};
use TYPO3\CMS\Core\Http\{Response, ServerRequest, Stream};
use TYPO3\CMS\Core\Security\RequestToken;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * RouteDispatcherTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RouteDispatcherTest extends FunctionalTestCase
{
    protected bool $initializeDatabase = false;

    protected array $testExtensionsToLoad = [
        'typo3_routing',
        __DIR__.'/../Fixtures/Extensions/routing_test',
    ];

    /**
     * A transient backend keeps the rate-limit buckets in-process and free of a DB cache table.
     */
    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'typo3_routing_ratelimit' => [
                        'frontend' => VariableFrontend::class,
                        'backend' => TransientMemoryBackend::class,
                    ],
                ],
            ],
        ],
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
        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Not Found","status":404}', (string) $response->getBody());
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
    public function passesPathPlaceholderAsTypedControllerArgument(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/item/42'));

        self::assertSame(200, $response->getStatusCode());
        // The controller declares `int $id`; the placeholder is cast and encoded as a JSON number.
        self::assertJsonStringEqualsJsonString('{"id":42}', (string) $response->getBody());
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
        // `int $q` arrives type-cast.
        self::assertJsonStringEqualsJsonString('{"q":42}', (string) $response->getBody());
    }

    #[Test]
    public function appliesDefaultsForOmittedOptionalArguments(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/range?from=3'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"from":3,"to":10,"label":null}', (string) $response->getBody());
    }

    #[Test]
    public function resolvesAllTypedArgumentsWhenProvided(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/range?from=3&to=7&label=days'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"from":3,"to":7,"label":"days"}', (string) $response->getBody());
    }

    #[Test]
    public function returnsBadRequestWhenRequiredTypedArgumentIsMissing(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/range'));

        self::assertSame(400, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Bad Request","status":400,"detail":"Missing required parameter: from"}', (string) $response->getBody());
    }

    #[Test]
    public function returnsBadRequestWhenTypedArgumentCannotBeCoerced(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/range?from=abc'));

        self::assertSame(400, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Bad Request","status":400,"detail":"Invalid value for parameter: from"}', (string) $response->getBody());
    }

    #[Test]
    public function resolvesBackedEnumFromPathPlaceholder(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/status/active'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"status":"active"}', (string) $response->getBody());
    }

    #[Test]
    public function returnsNotFoundForUnknownEnumPlaceholder(): void
    {
        // The matcher accepts any segment, but the enum cannot be resolved → 400.
        $response = $this->process($this->request('GET', 'https://example.com/api/example/status/bogus'));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function spreadsVariadicQueryArrayIntoArguments(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/tags?tags[]=php&tags[]=typo3'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"tags":["php","typo3"]}', (string) $response->getBody());
    }

    #[Test]
    public function resolvesParamNameOverrideFromQuery(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/aliased?q=hello'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"term":"hello"}', (string) $response->getBody());
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
    public function blocksRequestsExceedingTheRateLimitWith429(): void
    {
        $first = $this->process($this->request('GET', 'https://example.com/api/example/limited'));
        $second = $this->process($this->request('GET', 'https://example.com/api/example/limited'));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Too Many Requests","status":429}', (string) $second->getBody());
        self::assertNotSame('', $second->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function dispatchesBearerProtectedRouteWithAMatchingToken(): void
    {
        $_ENV['ROUTING_TEST_TOKEN'] = 'super-secret';

        try {
            $request = $this->request('GET', 'https://example.com/api/example/secure')
                ->withHeader('Authorization', 'Bearer super-secret');

            $response = $this->process($request);

            self::assertSame(200, $response->getStatusCode());
            self::assertJsonStringEqualsJsonString('{"secure":true}', (string) $response->getBody());
        } finally {
            unset($_ENV['ROUTING_TEST_TOKEN']);
        }
    }

    #[Test]
    public function rejectsBearerProtectedRouteWithAWrongToken(): void
    {
        $_ENV['ROUTING_TEST_TOKEN'] = 'super-secret';

        try {
            $request = $this->request('GET', 'https://example.com/api/example/secure')
                ->withHeader('Authorization', 'Bearer nope');

            $response = $this->process($request);

            self::assertSame(401, $response->getStatusCode());
            self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Unauthorized","status":401}', (string) $response->getBody());
        } finally {
            unset($_ENV['ROUTING_TEST_TOKEN']);
        }
    }

    #[Test]
    public function failsClosedWhenTheBearerTokenEnvVariableIsNotSet(): void
    {
        // No ROUTING_TEST_TOKEN in the environment — the route must be unreachable, not open.
        $request = $this->request('GET', 'https://example.com/api/example/secure')
            ->withHeader('Authorization', 'Bearer anything');

        self::assertSame(401, $this->process($request)->getStatusCode());
    }

    #[Test]
    public function rejectsAStateChangingRequestWithoutARequestToken(): void
    {
        $response = $this->process($this->request('POST', 'https://example.com/api/example/token'));

        self::assertSame(403, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Forbidden","status":403}', (string) $response->getBody());
    }

    #[Test]
    public function dispatchesAStateChangingRequestWithAMatchingRequestToken(): void
    {
        // Simulate what the core RequestTokenMiddleware does: provide the decoded token in the SecurityAspect.
        SecurityAspect::provideIn($this->get(Context::class))
            ->setReceivedRequestToken(RequestToken::create('routing/example-token'));

        $response = $this->process($this->request('POST', 'https://example.com/api/example/token'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"ok":true}', (string) $response->getBody());
    }

    #[Test]
    public function bindsJsonBodyToTypedArgumentsOnPost(): void
    {
        $response = $this->process($this->jsonRequest('POST', 'https://example.com/api/example/json', '{"title":"hello","priority":5}'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"title":"hello","priority":5}', (string) $response->getBody());
    }

    #[Test]
    public function bindsJsonBodyToTypedArgumentsOnPut(): void
    {
        $response = $this->process($this->jsonRequest('PUT', 'https://example.com/api/example/json', '{"title":"updated"}'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"title":"updated","priority":0}', (string) $response->getBody());
    }

    #[Test]
    public function returnsBadRequestWhenJsonBodyMissesARequiredParameter(): void
    {
        $response = $this->process($this->jsonRequest('POST', 'https://example.com/api/example/json', '{"priority":5}'));

        self::assertSame(400, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Bad Request","status":400,"detail":"Missing required parameter: title"}', (string) $response->getBody());
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

    private function jsonRequest(string $method, string $url, string $body): ServerRequest
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($body);
        $stream->rewind();

        return $this->request($method, $url)
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json');
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
