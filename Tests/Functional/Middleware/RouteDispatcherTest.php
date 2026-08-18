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

use KonradMichalik\Ttt\Assertion\JsonAssertions;
use KonradMichalik\Ttt\Http\RequestBuilder;
use KonradMichalik\Ttt\Traits\EnvVarSandbox;
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
    use EnvVarSandbox;
    use JsonAssertions;

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
                    // Keeps the response cache in-process so ETag/conditional-GET behaviour is testable
                    // without a database cache table.
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
    public function dispatchesMatchingRouteToController(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/count'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    /**
     * The route declares `/api/example/count` without a trailing slash; both forms reach it, so the
     * same endpoint never needs a second #[Route].
     */
    #[Test]
    public function dispatchesMatchingRouteWithAnAddedTrailingSlash(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/count/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    #[Test]
    public function mapsAControllerThrownProblemToAProblemDetailsResponse(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/problem'));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertJsonStringEqualsJsonString(
            '{"type":"about:blank","title":"Conflict","status":409,"detail":"Item already processed"}',
            (string) $response->getBody(),
        );
    }

    /**
     * No prefix is claimed exclusively here (the default), so an unmatched path — whether unknown, in
     * violation of a requirement, or barred by scheme/host — stays the page router's business. See
     * ExclusivePrefixesTest for the same paths under a configured claim.
     */
    #[Test]
    public function fallsThroughForUnknownPathWhenNothingIsClaimedExclusively(): void
    {
        $sentinel = new Response('php://temp', 418);

        $response = $this->process($this->request('GET', 'https://example.com/api/example/missing'), $sentinel);

        self::assertSame($sentinel, $response);
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
    public function routeOwnCorsAttributeAppliesWithoutAnyGlobalCorsConfiguration(): void
    {
        // This test instance has no cors.* extension configuration at all — the global policy is off.
        $response = $this->process(
            $this->request('GET', 'https://example.com/api/example/cors-override')
                ->withHeader('Origin', 'https://partner.example.org'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://partner.example.org', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function hidesTheSwaggerUiRoutesOutsideDevelopmentByDefault(): void
    {
        // Inert by default: the functional suite runs in "Testing" (not "Development"), and the
        // swaggerUi flag is unset — both gates independently keep these routes a 404.
        self::assertSame(404, $this->process($this->request('GET', 'https://example.com/api/_routing/openapi.json'))->getStatusCode());
        self::assertSame(404, $this->process($this->request('GET', 'https://example.com/api/_routing/docs'))->getStatusCode());
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
        self::assertNotSame('', $second->getHeaderLine('Retry-After'));

        $body = (string) $second->getBody();
        self::assertJsonPath($body, 'type', 'about:blank');
        self::assertJsonPath($body, 'title', 'Too Many Requests');
        self::assertJsonPath($body, 'status', 429);
        self::assertJsonPath($body, 'retryAfter', (int) $second->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function includesRateLimitHeadersOnTheContainerWiredDispatcher(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/limited'));

        self::assertSame('1', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function reportsACacheMissThenHitViaTheStatusHeader(): void
    {
        $first = $this->process($this->request('GET', 'https://example.com/api/example/cached'));
        $second = $this->process($this->request('GET', 'https://example.com/api/example/cached'));

        self::assertSame('MISS', $first->getHeaderLine('X-TYPO3-API-Cache'));
        self::assertSame('HIT', $second->getHeaderLine('X-TYPO3-API-Cache'));
    }

    #[Test]
    public function headSharesTheCacheEntryAGetRequestWrote(): void
    {
        $get = $this->process($this->request('GET', 'https://example.com/api/example/cached'));
        $head = $this->process($this->request('HEAD', 'https://example.com/api/example/cached'));

        self::assertSame('MISS', $get->getHeaderLine('X-TYPO3-API-Cache'));
        self::assertSame('HIT', $head->getHeaderLine('X-TYPO3-API-Cache'));
    }

    #[Test]
    public function headWritesTheCacheEntryAFollowingGetThenReads(): void
    {
        $head = $this->process($this->request('HEAD', 'https://example.com/api/example/cached'));
        $get = $this->process($this->request('GET', 'https://example.com/api/example/cached'));

        self::assertSame('MISS', $head->getHeaderLine('X-TYPO3-API-Cache'));
        self::assertSame('HIT', $get->getHeaderLine('X-TYPO3-API-Cache'));
        // The entry HEAD primed serves the subsequent GET's full body, not an empty one.
        self::assertNotSame('', (string) $get->getBody());
    }

    #[Test]
    public function headResponseHasAnEmptyBodyRegardlessOfCacheState(): void
    {
        $miss = $this->process($this->request('HEAD', 'https://example.com/api/example/cached'));
        $hit = $this->process($this->request('HEAD', 'https://example.com/api/example/cached'));

        self::assertSame('', (string) $miss->getBody());
        self::assertSame('', (string) $hit->getBody());
        self::assertSame('', $miss->getHeaderLine('Content-Length'));
        self::assertSame('', $hit->getHeaderLine('Content-Length'));
    }

    #[Test]
    public function headWithMatchingIfNoneMatchYieldsNotModifiedWithNoBody(): void
    {
        $first = $this->process($this->request('GET', 'https://example.com/api/example/cached'));
        $etag = $first->getHeaderLine('ETag');

        $second = $this->process(
            $this->request('HEAD', 'https://example.com/api/example/cached')->withHeader('If-None-Match', $etag),
        );

        self::assertSame(304, $second->getStatusCode());
        self::assertSame($etag, $second->getHeaderLine('ETag'));
        self::assertSame('', (string) $second->getBody());
    }

    #[Test]
    public function headOnARouteNotDeclaringGetStillYieldsMethodNotAllowedWithAllowHeader(): void
    {
        $response = $this->process($this->request('HEAD', 'https://example.com/api/example/submit'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
        self::assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function headResponseCarriesTheSameCorrelationAndRateLimitHeadersAsGet(): void
    {
        $response = $this->process($this->request('HEAD', 'https://example.com/api/example/cached'));

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function stampsACorrelationIdOnEveryResponse(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/count'));

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function matchesRouteWhenSchemeAndHostAreSatisfied(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/restricted'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function dispatchesBearerProtectedRouteWithAMatchingToken(): void
    {
        $this->setEnvVar('ROUTING_TEST_TOKEN', 'super-secret');

        $request = $this->request('GET', 'https://example.com/api/example/secure')
            ->withHeader('Authorization', 'Bearer super-secret');

        $response = $this->process($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"secure":true}', (string) $response->getBody());
    }

    #[Test]
    public function rejectsBearerProtectedRouteWithAWrongToken(): void
    {
        $this->setEnvVar('ROUTING_TEST_TOKEN', 'super-secret');

        $request = $this->request('GET', 'https://example.com/api/example/secure')
            ->withHeader('Authorization', 'Bearer nope');

        $response = $this->process($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Unauthorized","status":401}', (string) $response->getBody());
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
    public function attachesAnETagToCachedGetResponses(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/cached'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('"'.hash('sha256', (string) $response->getBody()).'"', $response->getHeaderLine('ETag'));
    }

    #[Test]
    public function answersConditionalGetWithNotModified(): void
    {
        $first = $this->process($this->request('GET', 'https://example.com/api/example/cached'));
        $etag = $first->getHeaderLine('ETag');

        $second = $this->process(
            $this->request('GET', 'https://example.com/api/example/cached')->withHeader('If-None-Match', $etag),
        );

        self::assertSame(304, $second->getStatusCode());
        self::assertSame($etag, $second->getHeaderLine('ETag'));
        self::assertSame('', (string) $second->getBody());
    }

    #[Test]
    public function generatesReachableUrlIncludingSiteBase(): void
    {
        $generator = $this->get(RouteUrlGenerator::class);
        $request = $this->request('GET', 'https://example.com/sub/', 'https://example.com/sub/');

        self::assertSame('/sub/api/example/count', $generator->generate($request, 'example_count'));
    }

    #[Test]
    public function matchesShorterPathForTrailingPlaceholderWithDefault(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/blog'));

        self::assertSame(200, $response->getStatusCode());
        // The controller receives the default, type-cast to int.
        self::assertJsonStringEqualsJsonString('{"page":1}', (string) $response->getBody());
    }

    #[Test]
    public function explicitSegmentOverridesTheRouteDefault(): void
    {
        $response = $this->process($this->request('GET', 'https://example.com/api/example/blog/5'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"page":5}', (string) $response->getBody());
    }

    #[Test]
    public function generatesUrlOmittingTrailingSegmentThatEqualsItsDefault(): void
    {
        $generator = $this->get(RouteUrlGenerator::class);
        $request = $this->request('GET', 'https://example.com/');

        // Omitted / equal to the default → shorter URL; a differing value keeps the segment.
        self::assertSame('/api/example/blog', $generator->generate($request, 'example_blog'));
        self::assertSame('/api/example/blog/5', $generator->generate($request, 'example_blog', ['page' => 5]));
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

        return (new RequestBuilder($method, $url))
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage())
            ->withQueryParams($query) // @phpstan-ignore argument.type
            ->build();
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
