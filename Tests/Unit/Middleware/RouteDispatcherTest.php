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
use KonradMichalik\Ttt\Assertion\JsonAssertions;
use KonradMichalik\Ttt\Attribute\WithEnvironment;
use KonradMichalik\Ttt\Http\RequestBuilder;
use KonradMichalik\Typo3Routing\Authentication\AccessGuard;
use KonradMichalik\Typo3Routing\Cache\{CacheBypassGuard, ResponseCacheManager};
use KonradMichalik\Typo3Routing\Http\{CorsHandler, CorsPreflightResolver, DeprecationHeaders, RouteUrlGenerator, SiteBasePathResolver};
use KonradMichalik\Typo3Routing\Middleware\{DispatcherServices, RouteDispatcher};
use KonradMichalik\Typo3Routing\RateLimit\{ClientKeyResolver, RateLimitCheck, RateLimitEnforcer};
use KonradMichalik\Typo3Routing\Routing\{ControllerArgumentResolver, ControllerInvoker, RouteMatcher, RouteRegistry, SiteLanguageScope};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Authentication\{DenyAuthenticator, FakeUser, PassAuthenticator};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\{CreatesResponseCacheManager, EntityController};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Entity\Item;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\{Context, UserAspect};
use TYPO3\CMS\Core\Http\{NormalizedParams, Response, ServerRequest, Stream};
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * RouteDispatcherTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RouteDispatcher::class)]
final class RouteDispatcherTest extends TestCase
{
    use CreatesResponseCacheManager;
    use JsonAssertions;

    private ResponseCacheManager $responseCache;

    private RateLimitCheck $rateLimitCheck;

    protected function setUp(): void
    {
        $this->responseCache = $this->createResponseCacheManager();
        $this->rateLimitCheck = new RateLimitCheck(new RateLimitEnforcer(new InMemoryStorage()));
    }

    #[Test]
    public function dispatchesMatchingRouteToController(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/count'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    #[Test]
    public function dispatchesMatchingRouteWithAnAddedTrailingSlash(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/count/'));

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    /**
     * The derived gate is the only thing in front of the matcher here, so this proves both halves: the
     * gate opens for the differently-cased path, and the matcher's fallback then dispatches it.
     */
    #[Test]
    public function dispatchesAnOptedInRouteThroughTheDerivedGateRegardlessOfCase(): void
    {
        $dispatcher = $this->dispatcherWithExclusivePrefixes('', $this->registry());

        $response = $dispatcher->process(
            $this->request('GET', 'https://example.com/API/Loose'),
            $this->handler(new Response('php://temp', 418)),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    #[Test]
    public function aDifferentlyCasedPathOfARouteThatDidNotOptInStillFallsThroughToThePage(): void
    {
        $dispatcher = $this->dispatcherWithExclusivePrefixes('', $this->registry());

        $response = $dispatcher->process(
            $this->request('GET', 'https://example.com/API/Count'),
            $this->handler(new Response('php://temp', 418)),
        );

        self::assertSame(418, $response->getStatusCode());
    }

    /**
     * The inverse direction has to clear the path gate first, and that gate is derived from the declared
     * paths — so a path declared *with* a trailing slash must contribute its slashless prefix as well.
     * Nothing is claimed exclusively here, so the derived gate is the only thing standing in front of
     * the matcher (as in a default installation).
     */
    #[Test]
    public function dispatchesARouteDeclaredWithATrailingSlashWhenTheRequestOmitsIt(): void
    {
        $dispatcher = $this->dispatcherWithExclusivePrefixes('', $this->registry());

        $response = $dispatcher->process(
            $this->request('GET', 'https://example.com/api/slashed'),
            $this->handler(new Response('php://temp', 418)),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    #[Test]
    public function answersACorsPreflightForARouteDeclaredWithATrailingSlashWhenTheRequestOmitsIt(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        // Path-less get() feeds CorsHandler; every keyed call yields '' — no prefix is claimed exclusively.
        $extensionConfiguration->method('get')->willReturnCallback(
            static fn (string $extension, string $path = ''): mixed => '' === $path
                ? ['cors' => ['allowedOrigins' => 'https://app.example.com']]
                : '',
        );

        $dispatcher = $this->dispatcherWith(new CorsHandler($extensionConfiguration), $extensionConfiguration, null, $this->registry());
        $request = $this->request('OPTIONS', 'https://example.com/api/slashed')
            ->withHeader('Origin', 'https://app.example.com')
            ->withHeader('Access-Control-Request-Method', 'GET');

        $response = $dispatcher->process($request, $this->handler(new Response('php://temp', 418)));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    #[Test]
    public function returnsMethodNotAllowedForTheTrailingSlashVariantOfAKnownPath(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/submit/'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
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
        $dispatcher = $this->dispatcherWithExclusivePrefixes('/api/, /va/');

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
        $dispatcher = $this->dispatcherWithExclusivePrefixes('/api/, /va/');

        $response = $dispatcher->process($this->request('GET', 'https://example.com/some/page'), $this->handler($sentinel));

        self::assertSame($sentinel, $response);
    }

    /**
     * The gate needs no configuration at all: it is derived from the registered route paths.
     */
    #[Test]
    public function dispatchesMatchingRouteWithoutAnyExclusivePrefixConfigured(): void
    {
        $dispatcher = $this->dispatcherWithExclusivePrefixes('');

        $response = $dispatcher->process(
            $this->request('GET', 'https://example.com/api/count'),
            $this->handler(new Response('php://temp', 200)),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    #[Test]
    public function fallsThroughInsteadOfNotFoundWhenNothingIsClaimedExclusively(): void
    {
        $sentinel = new Response('php://temp', 418);
        $dispatcher = $this->dispatcherWithExclusivePrefixes('');

        $response = $dispatcher->process($this->request('GET', 'https://example.com/some/page'), $this->handler($sentinel));

        self::assertSame($sentinel, $response);
    }

    /**
     * `/va/count/extra` clears the gate derived from the `/va/count` route but matches nothing, and only
     * `/api/` is claimed exclusively — so the path stays the page router's business.
     */
    #[Test]
    public function fallsThroughForUnmatchedPathInsideADerivedButNotExclusivePrefix(): void
    {
        $sentinel = new Response('php://temp', 418);
        $dispatcher = $this->dispatcherWithExclusivePrefixes('/api/');

        $response = $dispatcher->process($this->request('GET', 'https://example.com/va/count/extra'), $this->handler($sentinel));

        self::assertSame($sentinel, $response);
    }

    /**
     * `#[Route(exclusive: true)]` reaches the dispatcher purely through the registry-derived gate — no
     * `exclusivePrefixes` extension configuration is set here at all.
     */
    #[Test]
    public function returnsNotFoundForAnUnmatchedPathUnderAClassExclusivePrefix(): void
    {
        $sentinel = new Response('php://temp', 418);
        $dispatcher = $this->dispatcherWithExclusivePrefixes('');

        $response = $dispatcher->process($this->request('GET', 'https://example.com/api/exclusive/unknown'), $this->handler($sentinel));

        self::assertSame(404, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Not Found","status":404}', (string) $response->getBody());
    }

    #[Test]
    public function stillDispatchesTheMatchingRouteUnderAClassExclusivePrefix(): void
    {
        $dispatcher = $this->dispatcherWithExclusivePrefixes('');

        $response = $dispatcher->process(
            $this->request('GET', 'https://example.com/api/exclusive/known'),
            $this->handler(new Response('php://temp', 418)),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    /**
     * The claim is bound to the class's own path segment (a trailing slash), not merely to whatever
     * starts with the same characters — a sibling path that just happens to share the same leading
     * characters as the claimed prefix is not the class's business and must stay a page request.
     */
    #[Test]
    public function fallsThroughForASiblingPathSharingOnlyTheLeadingCharactersOfAClassExclusivePrefix(): void
    {
        $sentinel = new Response('php://temp', 418);
        $dispatcher = $this->dispatcherWithExclusivePrefixes('');

        $response = $dispatcher->process($this->request('GET', 'https://example.com/api/exclusive-other/thing'), $this->handler($sentinel));

        self::assertSame($sentinel, $response);
    }

    /**
     * Nothing registered and nothing claimed: the gate rejects every path before the matcher is built.
     */
    #[Test]
    public function fallsThroughImmediatelyWhenNoRoutesAreRegistered(): void
    {
        $sentinel = new Response('php://temp', 418);
        $dispatcher = $this->dispatcherWithExclusivePrefixes('', new RouteRegistry([], new ServiceLocator([])));

        $response = $dispatcher->process($this->request('GET', 'https://example.com/api/count'), $this->handler($sentinel));

        self::assertSame($sentinel, $response);
    }

    #[Test]
    public function stillReturnsMethodNotAllowedWithoutAnyExclusivePrefixConfigured(): void
    {
        $dispatcher = $this->dispatcherWithExclusivePrefixes('');

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
    public function returnsNotFoundWhenEntityParameterHasNoMatchingRecord(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/entity/1'));

        self::assertSame(404, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Not Found","status":404}', (string) $response->getBody());
    }

    #[Test]
    public function mapsAControllerThrownProblemToAProblemDetailsResponse(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/problem'));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertJsonStringEqualsJsonString(
            '{"type":"about:blank","title":"Conflict","status":409,"detail":"Item already processed"}',
            (string) $response->getBody(),
        );
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
    public function returnsBadRequestNamingTheBodyAsTheCauseForMalformedJson(): void
    {
        $response = $this->dispatch($this->jsonRequest('POST', 'https://example.com/api/json', '{"title":'));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Malformed JSON request body', (string) $response->getBody());
    }

    #[Test]
    public function returnsUnsupportedMediaTypeForANonEmptyBodyUnderAnUnreadableContentType(): void
    {
        $response = $this->dispatch($this->rawBodyRequest('POST', 'https://example.com/api/json', 'title=x', 'text/plain'));

        self::assertSame(415, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Accept-Post'));
    }

    #[Test]
    public function isUnaffectedByContentTypeWhenTheRouteBindsNoBodyArguments(): void
    {
        $response = $this->dispatch($this->rawBodyRequest('GET', 'https://example.com/api/count', 'irrelevant', 'text/plain'));

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
    #[WithEnvironment(context: 'Production')]
    public function hidesEnvBoundRouteOutsideItsContext(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/dev'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function doesNotRedirectAnExactPathMatchOnACanonicalRoute(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/canonical'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function redirectsATrailingSlashVariantOfACanonicalRouteToItsDeclaredPath(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/canonical/'));

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('/api/canonical', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function redirectsACaseInsensitiveVariantOfACanonicalRouteToItsDeclaredPath(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/Canonical-Loose'));

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('/api/canonical-loose', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function redirectsAPlaceholderRouteToTheConcretePathNotTheTemplate(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/canonical-item/42/'));

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('/api/canonical-item/42', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function includesTheSiteBaseInTheRedirectLocation(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/sub/api/canonical/', 'https://example.com/sub/'));

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('/sub/api/canonical', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function preservesTheQueryStringAcrossTheCanonicalRedirect(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/canonical/?foo=bar'));

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('/api/canonical?foo=bar', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function usesA308RedirectSoAPostIsNotDowngraded(): void
    {
        $response = $this->dispatch($this->request('POST', 'https://example.com/api/canonical/'));

        self::assertSame(308, $response->getStatusCode());
    }

    #[Test]
    public function methodNotAllowedTakesPrecedenceOverACanonicalRedirect(): void
    {
        $response = $this->dispatch($this->request('DELETE', 'https://example.com/api/canonical/'));

        self::assertSame(405, $response->getStatusCode());
    }

    #[Test]
    public function dispatchesARouteScopedToTheRequestsSite(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/scoped-site'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function hidesARouteScopedToADifferentSite(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/scoped-site-other'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function dispatchesARouteScopedToTheRequestsLanguage(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/scoped-language'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function hidesARouteScopedToADifferentLanguage(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/scoped-language-other'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function redirectsALegacyPathToTheDeclaredPathByDefault(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/old-name'));

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('/api/renamed', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function redirectsALegacyPlaceholderPathToTheConcretePathNotTheTemplate(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/old-item/42'));

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('/api/renamed-item/42', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function usesA308RedirectForALegacyPathSoAPostIsNotDowngraded(): void
    {
        $response = $this->dispatch($this->request('POST', 'https://example.com/api/old-name'));

        self::assertSame(308, $response->getStatusCode());
    }

    #[Test]
    public function doesNotRedirectTheDeclaredPathOfARouteWithALegacyPath(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/renamed'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function methodNotAllowedTakesPrecedenceOverALegacyPathRedirect(): void
    {
        $response = $this->dispatch($this->request('DELETE', 'https://example.com/api/old-name'));

        self::assertSame(405, $response->getStatusCode());
    }

    #[Test]
    public function anAliasedLegacyPathAnswersDirectlyWithoutARedirect(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/old-aliased'));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Location'));
    }

    #[Test]
    public function aLegacyPathRequestCarriesTheOwningRoutesDeprecationHeaders(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/old-deprecated'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('@1700000000', $response->getHeaderLine('Deprecation'));
    }

    #[Test]
    public function aSiteScopedRoutesLegacyPathStaysOutOfScopeTooThroughItsLegacyPath(): void
    {
        // The fixture request's site is "main"; the route (and thus its legacy path) is scoped to
        // "other-site" — proving the legacy-path entry carries the owning route's site scope too.
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/old-scoped-renamed'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function redirectsARequestOnTheWrongSchemeToTheDeclaredOne(): void
    {
        $response = $this->dispatch($this->request('GET', 'http://example.com/api/secure'));

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('https://example.com/api/secure', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function doesNotRedirectARequestOnTheDeclaredScheme(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/secure'));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Location'));
    }

    #[Test]
    public function preservesTheQueryStringAcrossTheSchemeRedirect(): void
    {
        $response = $this->dispatch($this->request('GET', 'http://example.com/api/secure?foo=bar'));

        self::assertSame('https://example.com/api/secure?foo=bar', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function keepsTheSiteBaseInTheSchemeRedirectLocation(): void
    {
        $response = $this->dispatch($this->request('GET', 'http://example.com/sub/api/secure', 'https://example.com/sub/'));

        self::assertSame('https://example.com/sub/api/secure', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function usesA308SchemeRedirectSoAPostIsNotDowngraded(): void
    {
        $response = $this->dispatch($this->request('POST', 'http://example.com/api/secure'));

        self::assertSame(308, $response->getStatusCode());
    }

    #[Test]
    public function methodNotAllowedTakesPrecedenceOverASchemeRedirect(): void
    {
        $response = $this->dispatch($this->request('DELETE', 'http://example.com/api/secure'));

        self::assertSame(405, $response->getStatusCode());
    }

    #[Test]
    #[WithEnvironment(context: 'Production')]
    public function hidesAnEnvBoundRouteInsteadOfRedirectingItsScheme(): void
    {
        $response = $this->dispatch($this->request('GET', 'http://example.com/api/secure-dev'));

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * Two things wrong at once (scheme and trailing slash) must still cost exactly one redirect: the
     * canonical redirect regenerates the URL through RouteUrlGenerator, which targets the declared
     * scheme on its own, so the location is already final. Following it lands on a 200.
     */
    #[Test]
    public function fixesTheSchemeAndTheTrailingSlashInASingleRedirect(): void
    {
        $response = $this->dispatch($this->request('GET', 'http://example.com/api/secure-canonical/'));

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('https://example.com/api/secure-canonical', $response->getHeaderLine('Location'));
        self::assertSame(200, $this->dispatch($this->request('GET', $response->getHeaderLine('Location')))->getStatusCode());
    }

    /**
     * The same for a non-canonical route: nothing else redirects, so the scheme redirect keeps the
     * tolerated trailing slash and the target is served directly rather than redirected again.
     */
    #[Test]
    public function aToleratedTrailingSlashSurvivesTheSchemeRedirectWithoutASecondHop(): void
    {
        $response = $this->dispatch($this->request('GET', 'http://example.com/api/secure/'));

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('https://example.com/api/secure/', $response->getHeaderLine('Location'));
        self::assertSame(200, $this->dispatch($this->request('GET', $response->getHeaderLine('Location')))->getStatusCode());
    }

    #[Test]
    public function redirectsALegacyPathOnTheWrongSchemeStraightToTheDeclaredUrl(): void
    {
        $response = $this->dispatch($this->request('GET', 'http://example.com/api/secure-old-name'));

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('https://example.com/api/secure-renamed', $response->getHeaderLine('Location'));
        self::assertSame(200, $this->dispatch($this->request('GET', $response->getHeaderLine('Location')))->getStatusCode());
    }

    /**
     * A legacy alias exists to keep answering on its own path, so the scheme redirect must not quietly
     * turn it into a redirect to the declared path — only the scheme changes.
     */
    #[Test]
    public function anAliasedLegacyPathKeepsItsOwnPathAcrossTheSchemeRedirect(): void
    {
        $response = $this->dispatch($this->request('GET', 'http://example.com/api/secure-old-aliased'));

        self::assertSame(308, $response->getStatusCode());
        self::assertSame('https://example.com/api/secure-old-aliased', $response->getHeaderLine('Location'));
        self::assertSame(200, $this->dispatch($this->request('GET', $response->getHeaderLine('Location')))->getStatusCode());
    }

    #[Test]
    public function fallsBackToDefaultPrefixWhenExtensionConfigurationThrows(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new RuntimeException('not configured'));

        $registry = $this->registry();
        $context = new Context();
        $cors = new CorsHandler($extensionConfiguration);
        $matcher = new RouteMatcher($registry, $extensionConfiguration);
        $dispatcher = new RouteDispatcher($registry, new SiteBasePathResolver(), $this->dispatcherServices($registry, $matcher, $cors, $context, new AccessGuard($registry, $context)), $extensionConfiguration);
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
        self::assertNotSame('', $second->getHeaderLine('Retry-After'));

        $body = (string) $second->getBody();
        self::assertJsonPath($body, 'type', 'about:blank');
        self::assertJsonPath($body, 'title', 'Too Many Requests');
        self::assertJsonPath($body, 'status', 429);
        self::assertJsonPath($body, 'retryAfter', (int) $second->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function throttlesAUserKeyedRoutePerFrontendUser(): void
    {
        $userA = $this->frontendUserContext(1);
        $first = $this->dispatch($this->request('GET', 'https://example.com/api/userlimited'), null, $userA);
        $second = $this->dispatch($this->request('GET', 'https://example.com/api/userlimited'), null, $userA);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());

        // A different frontend user has an independent bucket.
        $other = $this->dispatch($this->request('GET', 'https://example.com/api/userlimited'), null, $this->frontendUserContext(2));
        self::assertSame(200, $other->getStatusCode());
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
    public function includesRateLimitHeadersOnAnAcceptedResponse(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/limited'));

        self::assertSame('1', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
        self::assertNotSame('', $response->getHeaderLine('X-RateLimit-Reset'));
    }

    #[Test]
    public function includesRateLimitHeadersOnABlockedResponse(): void
    {
        $this->dispatch($this->request('GET', 'https://example.com/api/limited'));
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/limited'));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    #[Test]
    public function omitsRateLimitHeadersForRoutesWithoutTheAttribute(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/count'));

        self::assertSame('', $response->getHeaderLine('X-RateLimit-Limit'));
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

    #[Test]
    public function routeOwnCorsOverrideAppliesEvenWhenGlobalCorsIsDisabled(): void
    {
        $dispatcher = $this->dispatcher();
        $request = $this->request('GET', 'https://example.com/api/cors-override')->withHeader('Origin', 'https://partner.example.org');

        $response = $dispatcher->process($request, $this->handler(new Response('php://temp', 200)));

        self::assertSame('https://partner.example.org', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function routeOwnCorsOverrideIgnoresAGloballyAllowedOriginNotInItsOwnList(): void
    {
        $dispatcher = $this->dispatcherWithCors(['allowedOrigins' => 'https://admin.example.com']);
        $request = $this->request('GET', 'https://example.com/api/cors-override')->withHeader('Origin', 'https://admin.example.com');

        $response = $dispatcher->process($request, $this->handler(new Response('php://temp', 200)));

        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function preflightResolvesTheIntendedRouteAndItsOwnCorsOverride(): void
    {
        $dispatcher = $this->dispatcher();
        $request = $this->request('OPTIONS', 'https://example.com/api/cors-override')
            ->withHeader('Origin', 'https://partner.example.org')
            ->withHeader('Access-Control-Request-Method', 'POST');

        $response = $dispatcher->process($request, $this->handler(new Response('php://temp', 200)));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://partner.example.org', $response->getHeaderLine('Access-Control-Allow-Origin'));
        // "corsOverride" declares both GET and POST on the same route.
        self::assertSame('GET, POST, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('120', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    #[Test]
    public function preflightFallsBackToTheGlobalPolicyWhenTheIntendedMethodMatchesNoRoute(): void
    {
        // "submit" only accepts POST; a preflight for GET can't resolve a specific route or override.
        $dispatcher = $this->dispatcherWithCors(['allowedOrigins' => 'https://app.example.com']);
        $request = $this->request('OPTIONS', 'https://example.com/api/submit')
            ->withHeader('Origin', 'https://app.example.com')
            ->withHeader('Access-Control-Request-Method', 'GET');

        $response = $dispatcher->process($request, $this->handler(new Response('php://temp', 200)));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('POST, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    #[Test]
    public function marksACacheMissThenHitViaTheStatusHeader(): void
    {
        $first = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));
        $second = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));

        self::assertSame('MISS', $first->getHeaderLine('X-TYPO3-API-Cache'));
        self::assertSame('HIT', $second->getHeaderLine('X-TYPO3-API-Cache'));
    }

    #[Test]
    public function headSharesTheCacheEntryAGetRequestWrote(): void
    {
        $get = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));
        $head = $this->dispatch($this->request('HEAD', 'https://example.com/api/cached'));

        self::assertSame('MISS', $get->getHeaderLine('X-TYPO3-API-Cache'));
        self::assertSame('HIT', $head->getHeaderLine('X-TYPO3-API-Cache'));
    }

    #[Test]
    public function headWritesTheCacheEntryAFollowingGetThenReads(): void
    {
        $head = $this->dispatch($this->request('HEAD', 'https://example.com/api/cached'));
        $get = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));

        self::assertSame('MISS', $head->getHeaderLine('X-TYPO3-API-Cache'));
        self::assertSame('HIT', $get->getHeaderLine('X-TYPO3-API-Cache'));
        // The entry HEAD primed serves the subsequent GET's full body, not an empty one.
        self::assertNotSame('', (string) $get->getBody());
    }

    #[Test]
    public function headResponseHasAnEmptyBodyRegardlessOfCacheState(): void
    {
        $miss = $this->dispatch($this->request('HEAD', 'https://example.com/api/cached'));
        $hit = $this->dispatch($this->request('HEAD', 'https://example.com/api/cached'));

        self::assertSame('', (string) $miss->getBody());
        self::assertSame('', (string) $hit->getBody());
        self::assertSame('', $miss->getHeaderLine('Content-Length'));
        self::assertSame('', $hit->getHeaderLine('Content-Length'));
    }

    #[Test]
    public function headWithMatchingIfNoneMatchYieldsNotModifiedWithNoBody(): void
    {
        $first = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));
        $etag = $first->getHeaderLine('ETag');

        $second = $this->dispatch(
            $this->request('HEAD', 'https://example.com/api/cached')->withHeader('If-None-Match', $etag),
        );

        self::assertSame(304, $second->getStatusCode());
        self::assertSame($etag, $second->getHeaderLine('ETag'));
        self::assertSame('', (string) $second->getBody());
    }

    #[Test]
    public function headOnARouteNotDeclaringGetStillYieldsMethodNotAllowedWithAllowHeader(): void
    {
        $response = $this->dispatch($this->request('HEAD', 'https://example.com/api/submit'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
        self::assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function headResponseCarriesTheSameRequestIdHeaderAsGet(): void
    {
        $response = $this->dispatch($this->request('HEAD', 'https://example.com/api/count'));

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function stampsDeprecationHeadersOnASuccessResponse(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));

        self::assertSame('@1700000000', $response->getHeaderLine('Deprecation'));
        self::assertSame(gmdate('D, d M Y H:i:s', 1800000000).' GMT', $response->getHeaderLine('Sunset'));
        self::assertSame('</api/count>; rel="successor-version"', $response->getHeaderLine('Link'));
    }

    #[Test]
    public function stampsDeprecationHeadersOnACacheHit(): void
    {
        $this->dispatch($this->request('GET', 'https://example.com/api/cached'));
        $hit = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));

        self::assertSame('HIT', $hit->getHeaderLine('X-TYPO3-API-Cache'));
        self::assertSame('@1700000000', $hit->getHeaderLine('Deprecation'));
    }

    #[Test]
    public function stampsDeprecationHeadersOnAConditionalNotModifiedResponse(): void
    {
        $first = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));
        $etag = $first->getHeaderLine('ETag');

        $notModified = $this->dispatch(
            $this->request('GET', 'https://example.com/api/cached')->withHeader('If-None-Match', $etag),
        );

        self::assertSame(304, $notModified->getStatusCode());
        self::assertSame('@1700000000', $notModified->getHeaderLine('Deprecation'));
    }

    #[Test]
    public function stampsDeprecationHeadersOnA4xxResponse(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/guarded'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('@1700000000', $response->getHeaderLine('Deprecation'));
        // "guarded" declares no sunset/successor — neither header is emitted.
        self::assertSame('', $response->getHeaderLine('Sunset'));
        self::assertSame('', $response->getHeaderLine('Link'));
    }

    #[Test]
    public function omitsDeprecationHeadersForARouteWithoutTheAttribute(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/count'));

        self::assertSame('', $response->getHeaderLine('Deprecation'));
        self::assertSame('', $response->getHeaderLine('Sunset'));
        self::assertSame('', $response->getHeaderLine('Link'));
    }

    #[Test]
    public function omitsTheCacheStatusHeaderForRoutesWithoutCache(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/count'));

        self::assertSame('', $response->getHeaderLine('X-TYPO3-API-Cache'));
    }

    #[Test]
    public function bypassesTheCacheWhenTheClientSendsNoCache(): void
    {
        $this->dispatch($this->request('GET', 'https://example.com/api/cached'));

        $response = $this->dispatch(
            $this->request('GET', 'https://example.com/api/cached')->withHeader('Cache-Control', 'no-cache'),
        );

        self::assertSame('MISS', $response->getHeaderLine('X-TYPO3-API-Cache'));
    }

    #[Test]
    public function noCacheStillRefreshesTheStoredEntry(): void
    {
        $this->dispatch($this->request('GET', 'https://example.com/api/cached'));
        $refreshed = $this->dispatch(
            $this->request('GET', 'https://example.com/api/cached')->withHeader('Cache-Control', 'no-cache'),
        );
        $third = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));

        self::assertSame('HIT', $third->getHeaderLine('X-TYPO3-API-Cache'));
        self::assertSame((string) $refreshed->getBody(), (string) $third->getBody());
    }

    #[Test]
    public function noStoreNeitherReadsNorWritesTheCache(): void
    {
        $first = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));
        $bypassed = $this->dispatch(
            $this->request('GET', 'https://example.com/api/cached')->withHeader('Cache-Control', 'no-store'),
        );
        $third = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));

        self::assertSame('MISS', $bypassed->getHeaderLine('X-TYPO3-API-Cache'));
        self::assertNotSame((string) $first->getBody(), (string) $bypassed->getBody());
        // The no-store response was never written back, so the original cached entry still serves the third call.
        self::assertSame((string) $first->getBody(), (string) $third->getBody());
    }

    #[Test]
    public function bypassesTheCacheForALoggedInBackendUser(): void
    {
        $backendUserContext = $this->backendUserContext();

        $first = $this->dispatch($this->request('GET', 'https://example.com/api/cached'), null, $backendUserContext);
        $second = $this->dispatch($this->request('GET', 'https://example.com/api/cached'), null, $backendUserContext);

        self::assertSame('MISS', $first->getHeaderLine('X-TYPO3-API-Cache'));
        self::assertSame('MISS', $second->getHeaderLine('X-TYPO3-API-Cache'));
        self::assertNotSame((string) $first->getBody(), (string) $second->getBody());
    }

    #[Test]
    public function aBackendUsersResponseIsNeverStoredForLaterAnonymousRequests(): void
    {
        $this->dispatch($this->request('GET', 'https://example.com/api/cached'), null, $this->backendUserContext());

        $anonymous = $this->dispatch($this->request('GET', 'https://example.com/api/cached'));

        self::assertSame('MISS', $anonymous->getHeaderLine('X-TYPO3-API-Cache'));
    }

    #[Test]
    public function stampsAGeneratedRequestIdOnASuccessResponse(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/count'));

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function echoesTheClientsRequestId(): void
    {
        $request = $this->request('GET', 'https://example.com/api/count')->withHeader('X-Request-ID', 'client-supplied-id');

        $response = $this->dispatch($request);

        self::assertSame('client-supplied-id', $response->getHeaderLine('X-Request-ID'));
    }

    #[Test]
    public function stampsARequestIdOnErrorResponsesToo(): void
    {
        $response = $this->dispatch($this->request('GET', 'https://example.com/api/missing'));

        self::assertSame(404, $response->getStatusCode());
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $response->getHeaderLine('X-Request-ID'));
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

    private function dispatcherWithExclusivePrefixes(string $prefixes, ?RouteRegistry $registry = null): RouteDispatcher
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($prefixes);

        return $this->dispatcherWith(new CorsHandler($extensionConfiguration), $extensionConfiguration, null, $registry);
    }

    /**
     * @param array<string, mixed> $cors
     */
    private function dispatcherWithCors(array $cors, ?Context $context = null): RouteDispatcher
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        // The path-less get() feeds CorsHandler the full config; the get(..., 'exclusivePrefixes') call resolves the claim.
        $extensionConfiguration->method('get')->willReturnCallback(
            static fn (string $extension, string $path = ''): mixed => '' === $path ? ['cors' => $cors] : '/api/',
        );

        return $this->dispatcherWith(new CorsHandler($extensionConfiguration), $extensionConfiguration, $context);
    }

    private function dispatcherWith(CorsHandler $cors, ExtensionConfiguration $extensionConfiguration, ?Context $context = null, ?RouteRegistry $registry = null): RouteDispatcher
    {
        $registry ??= $this->registry();
        $context ??= new Context();
        $accessGuard = new AccessGuard($registry, $context);

        $matcher = new RouteMatcher($registry, $extensionConfiguration);

        return new RouteDispatcher($registry, new SiteBasePathResolver(), $this->dispatcherServices($registry, $matcher, $cors, $context, $accessGuard), $extensionConfiguration);
    }

    /**
     * The dispatcher reaches everything it needs after the path gate through a locator rather than its
     * constructor (see DispatcherServices). A real ServiceLocator is built here rather than a mock,
     * because mis-wiring one is exactly what DispatcherServices asserts against, and a mock would let
     * that mistake through.
     */
    private function dispatcherServices(RouteRegistry $registry, RouteMatcher $matcher, CorsHandler $cors, Context $context, AccessGuard $accessGuard): DispatcherServices
    {
        return new DispatcherServices(new ServiceLocator([
            RouteMatcher::class => static fn (): RouteMatcher => $matcher,
            ResponseCacheManager::class => fn (): ResponseCacheManager => $this->responseCache,
            RateLimitCheck::class => fn (): RateLimitCheck => $this->rateLimitCheck,
            ControllerInvoker::class => fn (): ControllerInvoker => new ControllerInvoker($registry, new ControllerArgumentResolver($this->createMock(PersistenceManagerInterface::class))),
            AccessGuard::class => static fn (): AccessGuard => $accessGuard,
            CorsHandler::class => static fn (): CorsHandler => $cors,
            CorsPreflightResolver::class => static fn (): CorsPreflightResolver => new CorsPreflightResolver($registry, $matcher, $cors),
            CacheBypassGuard::class => static fn (): CacheBypassGuard => new CacheBypassGuard($context),
            ClientKeyResolver::class => static fn (): ClientKeyResolver => new ClientKeyResolver($context),
            RouteUrlGenerator::class => static fn (): RouteUrlGenerator => new RouteUrlGenerator($registry, new SiteBasePathResolver()),
            SiteLanguageScope::class => $this->siteLanguageScope(...),
            DeprecationHeaders::class => fn (): DeprecationHeaders => $this->deprecationHeaders($registry),
        ]));
    }

    private function siteLanguageScope(): SiteLanguageScope
    {
        return new SiteLanguageScope($this->createMock(SiteFinder::class), $this->createMock(LogManager::class));
    }

    private function deprecationHeaders(RouteRegistry $registry): DeprecationHeaders
    {
        return new DeprecationHeaders($registry, new RouteUrlGenerator($registry, new SiteBasePathResolver()));
    }

    private function registry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, caseInsensitive?: bool, canonical?: bool, sites?: list<string>, languages?: list<int>, legacyPaths?: list<string>, legacyAlias?: bool, schemes?: list<string>}> $routes */
        $routes = [
            'secure' => ['path' => '/api/secure', 'methods' => ['GET', 'POST'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'schemes' => ['https']],
            'secureCanonical' => ['path' => '/api/secure-canonical', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'schemes' => ['https'], 'canonical' => true],
            'secureRenamed' => ['path' => '/api/secure-renamed', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'schemes' => ['https'], 'legacyPaths' => ['/api/secure-old-name']],
            'secureAliased' => ['path' => '/api/secure-aliased', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'schemes' => ['https'], 'legacyPaths' => ['/api/secure-old-aliased'], 'legacyAlias' => true],
            'secureDev' => ['path' => '/api/secure-dev', 'methods' => ['GET'], 'controller' => 'ctrl::dev', 'env' => 'Development', 'requirements' => [], 'schemes' => ['https']],
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
            'userlimited' => ['path' => '/api/userlimited', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'denied' => ['path' => '/api/denied', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'securecached' => ['path' => '/api/securecached', 'methods' => ['GET'], 'controller' => 'ctrl::cached', 'env' => null, 'requirements' => []],
            'optionated' => ['path' => '/api/optionated', 'methods' => ['GET', 'OPTIONS'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'entity' => ['path' => '/api/entity/{item}', 'methods' => ['GET'], 'controller' => 'entityCtrl::show', 'env' => null, 'requirements' => []],
            'problem' => ['path' => '/api/problem', 'methods' => ['GET'], 'controller' => 'ctrl::problem', 'env' => null, 'requirements' => []],
            'corsOverride' => ['path' => '/api/cors-override', 'methods' => ['GET', 'POST'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'slashed' => ['path' => '/api/slashed/', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'loose' => ['path' => '/api/loose', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'caseInsensitive' => true],
            'exclusiveKnown' => ['path' => '/api/exclusive/known', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'classExclusivePrefix' => '/api/exclusive/'],
            'json' => ['path' => '/api/json', 'methods' => ['POST'], 'controller' => 'ctrl::json', 'env' => null, 'requirements' => []],
            'canonical' => ['path' => '/api/canonical', 'methods' => ['GET', 'POST'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'canonical' => true],
            'canonicalItem' => ['path' => '/api/canonical-item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::item', 'env' => null, 'requirements' => ['id' => '\d+'], 'canonical' => true],
            'canonicalLoose' => ['path' => '/api/canonical-loose', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'caseInsensitive' => true, 'canonical' => true],
            'scopedSite' => ['path' => '/api/scoped-site', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'sites' => ['main']],
            'scopedSiteOther' => ['path' => '/api/scoped-site-other', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'sites' => ['other-site']],
            'scopedLanguage' => ['path' => '/api/scoped-language', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'languages' => [0]],
            'scopedLanguageOther' => ['path' => '/api/scoped-language-other', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'languages' => [1]],
            'renamed' => ['path' => '/api/renamed', 'methods' => ['GET', 'POST'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'legacyPaths' => ['/api/old-name']],
            'renamedItem' => ['path' => '/api/renamed-item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::item', 'env' => null, 'requirements' => ['id' => '\d+'], 'legacyPaths' => ['/api/old-item/{id}']],
            'aliasedLegacy' => ['path' => '/api/aliased', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'legacyPaths' => ['/api/old-aliased'], 'legacyAlias' => true],
            'deprecatedRenamed' => ['path' => '/api/deprecated-renamed', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'legacyPaths' => ['/api/old-deprecated'], 'legacyAlias' => true],
            'scopedRenamed' => ['path' => '/api/scoped-renamed', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'sites' => ['other-site'], 'legacyPaths' => ['/api/old-scoped-renamed'], 'legacyAlias' => true],
        ];

        /** @var array<string, array{lifetime: int, tags: list<string>, ignoreParams: list<string>}> $cacheConfigs */
        $cacheConfigs = [
            'cached' => ['lifetime' => 3600, 'tags' => ['pages'], 'ignoreParams' => []],
            // Combined with an authenticator below — the dispatcher must force no-store regardless.
            'securecached' => ['lifetime' => 3600, 'tags' => ['pages'], 'ignoreParams' => []],
        ];

        /** @var array<string, array{limit: int, interval: string, policy: string, keyBy: string}> $rateLimits */
        $rateLimits = [
            'limited' => ['limit' => 1, 'interval' => '1 minute', 'policy' => 'sliding_window', 'keyBy' => 'ip'],
            'userlimited' => ['limit' => 1, 'interval' => '1 minute', 'policy' => 'sliding_window', 'keyBy' => 'user'],
        ];

        $request = ['name' => 'request', 'type' => null, 'source' => 'request', 'nullable' => false, 'hasDefault' => false, 'default' => null];
        $id = ['name' => 'id', 'type' => 'int', 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null];

        /** @var array<string, list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>> $arguments */
        $arguments = [
            'secure' => [],
            'secureCanonical' => [],
            'secureRenamed' => [],
            'secureAliased' => [],
            'secureDev' => [],
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
            'userlimited' => [],
            'denied' => [],
            'securecached' => [],
            'optionated' => [],
            'entity' => [['name' => 'item', 'type' => Item::class, 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null]],
            'problem' => [],
            'corsOverride' => [],
            'slashed' => [],
            'loose' => [],
            'exclusiveKnown' => [],
            'json' => [
                ['name' => 'title', 'type' => 'string', 'source' => 'input', 'nullable' => false, 'hasDefault' => false, 'default' => null],
                ['name' => 'priority', 'type' => 'int', 'source' => 'input', 'nullable' => false, 'hasDefault' => true, 'default' => 0],
            ],
            'canonical' => [],
            'canonicalItem' => [$id],
            'canonicalLoose' => [],
            'scopedSite' => [],
            'scopedSiteOther' => [],
            'scopedLanguage' => [],
            'scopedLanguageOther' => [],
            'renamed' => [],
            'renamedItem' => [$id],
            'aliasedLegacy' => [],
            'scopedRenamed' => [],
        ];

        /** @var array<string, list<array{service: string, options: array<string, mixed>}>> $authenticators */
        $authenticators = [
            'denied' => [['service' => DenyAuthenticator::class, 'options' => []]],
            'securecached' => [['service' => PassAuthenticator::class, 'options' => []]],
        ];

        /** @var array<string, string> $requestTokenScopes */
        $requestTokenScopes = [];

        /** @var array<string, array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}> $corsConfigs */
        $corsConfigs = [
            'corsOverride' => ['allowedOrigins' => ['https://partner.example.org'], 'allowedHeaders' => 'Content-Type, Authorization', 'allowCredentials' => false, 'exposeHeaders' => '', 'maxAge' => 120],
        ];

        /** @var array<string, array{since: int, sunset: int|null, successor: string|null, documentation: string|null}> $deprecations */
        $deprecations = [
            'cached' => ['since' => 1700000000, 'sunset' => 1800000000, 'successor' => 'count', 'documentation' => null],
            'guarded' => ['since' => 1700000000, 'sunset' => null, 'successor' => null, 'documentation' => null],
            'deprecatedRenamed' => ['since' => 1700000000, 'sunset' => null, 'successor' => null, 'documentation' => null],
        ];

        $locator = new ServiceLocator([
            'ctrl' => static fn (): ExampleController => new ExampleController(),
            'entityCtrl' => static fn (): EntityController => new EntityController(),
        ]);
        $authenticatorLocator = new ServiceLocator([
            PassAuthenticator::class => static fn (): PassAuthenticator => new PassAuthenticator(),
            DenyAuthenticator::class => static fn (): DenyAuthenticator => new DenyAuthenticator(),
        ]);

        return new RouteRegistry($routes, $locator, $cacheConfigs, $rateLimits, $arguments, $authenticators, $requestTokenScopes, $authenticatorLocator, corsConfigs: $corsConfigs, deprecations: $deprecations);
    }

    private function frontendUserContext(int $uid): Context
    {
        $user = new FakeUser();
        $user->user = ['uid' => $uid];

        $context = new Context();
        $context->setAspect('frontend.user', new UserAspect($user));

        return $context;
    }

    private function backendUserContext(): Context
    {
        $user = new FakeUser();
        $user->user = ['uid' => 1];

        $context = new Context();
        $context->setAspect('backend.user', new UserAspect($user));

        return $context;
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

        return (new RequestBuilder($method, $url))
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage())
            ->withQueryParams($query) // @phpstan-ignore argument.type
            ->build();
    }

    private function jsonRequest(string $method, string $url, string $body): ServerRequest
    {
        return $this->rawBodyRequest($method, $url, $body, 'application/json');
    }

    private function rawBodyRequest(string $method, string $url, string $body, string $contentType): ServerRequest
    {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($body);
        $stream->rewind();

        return $this->request($method, $url)
            ->withBody($stream)
            ->withHeader('Content-Type', $contentType);
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
