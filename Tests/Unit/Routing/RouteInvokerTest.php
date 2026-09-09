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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Routing;

use InvalidArgumentException;
use KonradMichalik\RoutingTest\Controller\ExampleController;
use KonradMichalik\Ttt\Assertion\JsonAssertions;
use KonradMichalik\Ttt\Http\RequestBuilder;
use KonradMichalik\Typo3Routing\Authentication\AccessGuard;
use KonradMichalik\Typo3Routing\Http\{RouteUrlGenerator, SiteBasePathResolver};
use KonradMichalik\Typo3Routing\Routing\{ControllerArgumentResolver, ControllerInvoker, RouteInvoker, RouteRegistry, SiteLanguageScope};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Authentication\{DenyAuthenticator, HeaderAuthenticator, PassAuthenticator};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Entity\Item;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\{EntityController, InvokerProbeController};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * RouteInvokerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RouteInvoker::class)]
final class RouteInvokerTest extends TestCase
{
    use JsonAssertions;

    #[Test]
    public function invokesTheControllerOfTheNamedRoute(): void
    {
        $response = $this->invoker()->invoke('count', [], $this->request());

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    #[Test]
    public function throwsForAnUnknownRouteName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No route named "nope" is registered.');

        $this->invoker()->invoke('nope', [], $this->request());
    }

    #[Test]
    public function resolvesAPathPlaceholderFromTheInput(): void
    {
        $response = $this->invoker()->invoke('item', ['id' => 42], $this->request());

        self::assertJsonStringEqualsJsonString('{"id":42}', (string) $response->getBody());
    }

    #[Test]
    public function appliesARouteDefaultForAnOmittedOptionalPlaceholder(): void
    {
        $response = $this->invoker()->invoke('blog', [], $this->request());

        self::assertJsonStringEqualsJsonString('{"page":1}', (string) $response->getBody());
    }

    #[Test]
    public function returnsNotFoundWhenAPathValueViolatesItsRequirement(): void
    {
        $response = $this->invoker()->invoke('item', ['id' => 'abc'], $this->request());

        self::assertSame(404, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"type":"about:blank","title":"Not Found","status":404}', (string) $response->getBody());
    }

    #[Test]
    public function returnsNotFoundWhenAMandatoryPlaceholderIsMissing(): void
    {
        $response = $this->invoker()->invoke('item', [], $this->request());

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function resolvesAnInputSourcedArgument(): void
    {
        $response = $this->invoker()->invoke('search', ['q' => 7], $this->request());

        self::assertJsonStringEqualsJsonString('{"q":7}', (string) $response->getBody());
    }

    #[Test]
    public function resolvesABodySourcedArgument(): void
    {
        $response = $this->invoker()->invoke('body', ['title' => 'invoked', 'priority' => 3], $this->request());

        self::assertJsonStringEqualsJsonString('{"title":"invoked","priority":3}', (string) $response->getBody());
    }

    /**
     * On PUT/PATCH/DELETE, TYPO3's own form decoding makes RequestBody ignore the parsed body and
     * read the raw stream — so a synthetic body has to be written there, not just set as parsed.
     */
    #[Test]
    public function resolvesABodySourcedArgumentOnAPutRoute(): void
    {
        $response = $this->invoker()->invoke('putBody', ['title' => 'invoked', 'priority' => 3], $this->request());

        self::assertJsonStringEqualsJsonString('{"title":"invoked","priority":3}', (string) $response->getBody());
    }

    #[Test]
    public function carriesTheBodyAsAJsonPayloadWithItsContentType(): void
    {
        $response = $this->invoker()->invoke('probeBody', ['note' => 'hello'], $this->request());

        self::assertJsonPath((string) $response->getBody(), 'contentType', 'application/json');
        self::assertJsonPath((string) $response->getBody(), 'body', '{"note":"hello"}');
    }

    /**
     * A placeholder whose argument declares a non-path source needs its value in both places: the
     * path cannot be generated without it, and the argument does not read the match.
     */
    #[Test]
    public function resolvesAPlaceholderWhoseArgumentOverridesItsSource(): void
    {
        $response = $this->invoker()->invoke('overridden', ['id' => 42], $this->request());

        self::assertJsonStringEqualsJsonString('{"id":42}', (string) $response->getBody());
    }

    /**
     * `$input` is a mixed map, so a caller can hand over a value no JSON body could carry. That is a
     * client error like any other bad input — not an exception escaping the response contract.
     */
    #[Test]
    public function mapsABodyValueThatCannotBeSerialisedToBadRequest(): void
    {
        $response = $this->invoker()->invoke('body', ['title' => "\xB1\x31"], $this->request());

        self::assertSame(400, $response->getStatusCode());
        self::assertJsonPath((string) $response->getBody(), 'detail', 'Invalid body input');
    }

    #[Test]
    public function resolvesAVariadicArgument(): void
    {
        $response = $this->invoker()->invoke('tags', ['tags' => ['a', 'b']], $this->request());

        self::assertJsonStringEqualsJsonString('{"tags":["a","b"]}', (string) $response->getBody());
    }

    #[Test]
    public function resolvesAnAliasedArgumentByItsWireName(): void
    {
        $response = $this->invoker()->invoke('aliased', ['q' => 'chair'], $this->request());

        self::assertJsonStringEqualsJsonString('{"term":"chair"}', (string) $response->getBody());
    }

    #[Test]
    public function injectsTheSyntheticRequestForARequestSourcedArgument(): void
    {
        $response = $this->invoker()->invoke('submit', [], $this->request());

        // The method comes from the route definition, not from the calling request.
        self::assertJsonStringEqualsJsonString('{"submitted":true,"method":"POST"}', (string) $response->getBody());
    }

    #[Test]
    public function buildsTheSyntheticPathFromTheRouteAndItsPlaceholders(): void
    {
        $response = $this->invoker()->invoke('probe', ['id' => 7], $this->request());

        self::assertJsonPath((string) $response->getBody(), 'path', '/api/probe/7');
    }

    #[Test]
    public function targetsTheRoutesOwnHostWhenItIsConstrainedToAnother(): void
    {
        $response = $this->invoker()->invoke('restricted', [], $this->request());

        self::assertJsonPath((string) $response->getBody(), 'host', 'api.example.com');
        self::assertJsonPath((string) $response->getBody(), 'path', '/api/restricted');
    }

    #[Test]
    public function carriesANonAsciiPlaceholderInTheSyntheticPath(): void
    {
        $response = $this->invoker()->invoke('coins', ['münze' => 'taler'], $this->request());

        self::assertJsonPath((string) $response->getBody(), 'path', '/api/coins/taler');
    }

    #[Test]
    public function answers404ForARouteWhoseHostCarriesAPlaceholder(): void
    {
        // The route's own URL cannot be generated without a value for the host placeholder, and this
        // seam has no host to take one from — the same answer an unmatched path gets over HTTP.
        $response = $this->invoker()->invoke('tenant', ['subdomain' => 'acme'], $this->request());

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function exposesPathPlaceholdersAsRequestAttributes(): void
    {
        $response = $this->invoker()->invoke('probe', ['id' => 7], $this->request());

        self::assertJsonPath((string) $response->getBody(), 'idAttribute', '7');
    }

    #[Test]
    public function doesNotLeakTheCallingRequestsBodyIntoBodySourcedArguments(): void
    {
        $caller = (new RequestBuilder('POST', 'https://example.com/mcp'))
            ->withAttribute('site', $this->site())
            ->withAttribute('language', $this->site()->getDefaultLanguage())
            ->withJsonBody(['title' => 'caller', 'priority' => 99])
            ->build();

        $response = $this->invoker()->invoke('body', [], $caller);

        self::assertJsonStringEqualsJsonString('{"title":"none","priority":0}', (string) $response->getBody());
    }

    #[Test]
    public function dropsTheCallingRequestsQueryParameters(): void
    {
        $caller = (new RequestBuilder('POST', 'https://example.com/mcp?secret=1'))
            ->withAttribute('site', $this->site())
            ->withAttribute('language', $this->site()->getDefaultLanguage())
            ->withQueryParams(['secret' => '1'])
            ->build();

        $response = $this->invoker()->invoke('probe', ['id' => 7], $caller);

        self::assertJsonPath((string) $response->getBody(), 'query', []);
    }

    #[Test]
    public function ignoresInputKeysNoRouteArgumentClaims(): void
    {
        $response = $this->invoker()->invoke('count', ['bogus' => true], $this->request());

        self::assertJsonStringEqualsJsonString('{"count":3}', (string) $response->getBody());
    }

    #[Test]
    public function returnsNotFoundForARouteBoundToAnotherApplicationContext(): void
    {
        $response = $this->invoker()->invoke('dev', [], $this->request());

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * The "guarded" route puts its requirement on a key no controller argument reads, so only the
     * input-requirement gate can reject it — argument resolution would never look at it.
     */
    #[Test]
    public function returnsBadRequestWhenARequiredInputIsMissing(): void
    {
        $response = $this->invoker()->invoke('guarded', [], $this->request());

        self::assertSame(400, $response->getStatusCode());
        self::assertJsonPath((string) $response->getBody(), 'detail', 'Missing required parameter: q');
    }

    #[Test]
    public function returnsBadRequestWhenAnInputViolatesItsRequirement(): void
    {
        $response = $this->invoker()->invoke('guarded', ['q' => 'abc'], $this->request());

        self::assertSame(400, $response->getStatusCode());
        self::assertJsonPath((string) $response->getBody(), 'detail', 'Invalid value for parameter: q');
    }

    #[Test]
    public function acceptsAnInputSatisfyingItsRequirement(): void
    {
        $response = $this->invoker()->invoke('guarded', ['q' => 5], $this->request());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returnsUnauthorizedForAnAuthenticatedRouteThatDeniesAccess(): void
    {
        $response = $this->invoker()->invoke('denied', [], $this->request());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function handsTheCallingRequestsCredentialsToTheAuthenticator(): void
    {
        $response = $this->invoker()->invoke('header', [], $this->requestWithHeader('Authorization', HeaderAuthenticator::HEADER));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returnsUnauthorizedWhenTheCallingRequestCarriesNoCredentials(): void
    {
        $response = $this->invoker()->invoke('header', [], $this->request());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function skipsTheRequestTokenCheckAnHttpCallWouldEnforce(): void
    {
        // Over HTTP this POST route answers 403 without a matching request token in the security
        // aspect; a programmatic invocation has no browser and no such token to carry.
        $response = $this->invoker()->invoke('token', [], $this->request());

        self::assertSame(200, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"ok":true}', (string) $response->getBody());
    }

    #[Test]
    public function skipsTheResponseCache(): void
    {
        $invoker = $this->invoker();

        $first = (string) $invoker->invoke('cached', [], $this->request())->getBody();
        $second = (string) $invoker->invoke('cached', [], $this->request())->getBody();

        // The controller mints a fresh token per call — identical bodies would prove a cache hit.
        self::assertNotSame($first, $second);
    }

    #[Test]
    public function stampsNoCacheStatusHeaderOnTheResponse(): void
    {
        $response = $this->invoker()->invoke('cached', [], $this->request());

        self::assertFalse($response->hasHeader('Cache-Status'));
    }

    #[Test]
    public function skipsRateLimiting(): void
    {
        $invoker = $this->invoker();

        $invoker->invoke('limited', [], $this->request());
        $invoker->invoke('limited', [], $this->request());
        $response = $invoker->invoke('limited', [], $this->request());

        // The route allows a single request per minute over HTTP.
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-RateLimit-Remaining'));
    }

    #[Test]
    public function mapsAnUnresolvableArgumentToBadRequest(): void
    {
        $response = $this->invoker()->invoke('range', [], $this->request());

        self::assertSame(400, $response->getStatusCode());
        self::assertJsonPath((string) $response->getBody(), 'detail', 'Missing required parameter: from');
    }

    #[Test]
    public function mapsAControllerProblemToItsOwnStatus(): void
    {
        $response = $this->invoker()->invoke('problem', [], $this->request());

        self::assertSame(409, $response->getStatusCode());
        self::assertJsonPath((string) $response->getBody(), 'detail', 'Item already processed');
    }

    #[Test]
    public function mapsAMissingEntityToNotFound(): void
    {
        $response = $this->invoker()->invoke('entity', ['item' => 5], $this->request());

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function invokesARouteScopedToTheCallingRequestsSite(): void
    {
        $response = $this->invoker()->invoke('scopedSite', [], $this->request());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returnsNotFoundForARouteScopedToAnotherSite(): void
    {
        $response = $this->invoker()->invoke('scopedSiteOther', [], $this->request());

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function invokesARouteScopedToTheCallingRequestsLanguage(): void
    {
        $response = $this->invoker()->invoke('scopedLanguage', [], $this->request());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returnsNotFoundForARouteScopedToAnotherLanguage(): void
    {
        $response = $this->invoker()->invoke('scopedLanguageOther', [], $this->request());

        self::assertSame(404, $response->getStatusCode());
    }

    private function invoker(?Context $context = null): RouteInvoker
    {
        $registry = $this->registry();

        return new RouteInvoker(
            $registry,
            new ControllerInvoker($registry, new ControllerArgumentResolver($this->createMock(PersistenceManagerInterface::class))),
            new AccessGuard($registry, $context ?? new Context()),
            new RouteUrlGenerator($registry, new SiteBasePathResolver()),
            new SiteLanguageScope($this->createMock(SiteFinder::class), $this->createMock(LogManager::class)),
        );
    }

    private function registry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, sites?: list<string>, languages?: list<int>}> $routes */
        $routes = [
            'count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'item' => ['path' => '/api/item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::item', 'env' => null, 'requirements' => ['id' => '\d+']],
            'blog' => ['path' => '/api/blog/{page}', 'methods' => ['GET'], 'controller' => 'ctrl::blog', 'env' => null, 'requirements' => ['page' => '\d+'], 'defaults' => ['page' => 1]],
            'search' => ['path' => '/api/search', 'methods' => ['GET'], 'controller' => 'ctrl::search', 'env' => null, 'requirements' => ['q' => '\d+']],
            'tags' => ['path' => '/api/tags', 'methods' => ['GET'], 'controller' => 'ctrl::tags', 'env' => null, 'requirements' => []],
            'aliased' => ['path' => '/api/aliased', 'methods' => ['GET'], 'controller' => 'ctrl::aliased', 'env' => null, 'requirements' => []],
            'submit' => ['path' => '/api/submit', 'methods' => ['POST'], 'controller' => 'ctrl::submit', 'env' => null, 'requirements' => []],
            'restricted' => ['path' => '/api/restricted', 'methods' => ['GET'], 'controller' => 'probe::probe', 'env' => null, 'requirements' => [], 'schemes' => ['https'], 'host' => 'api.example.com'],
            'tenant' => ['path' => '/api/tenant', 'methods' => ['GET'], 'controller' => 'probe::probe', 'env' => null, 'requirements' => ['subdomain' => '\w+'], 'host' => '{subdomain}.example.com'],
            'coins' => ['path' => '/api/coins/{münze}', 'methods' => ['GET'], 'controller' => 'probe::probe', 'env' => null, 'requirements' => []],
            'probe' => ['path' => '/api/probe/{id}', 'methods' => ['POST'], 'controller' => 'probe::probe', 'env' => null, 'requirements' => []],
            'body' => ['path' => '/api/body', 'methods' => ['POST'], 'controller' => 'probe::body', 'env' => null, 'requirements' => []],
            'putBody' => ['path' => '/api/put-body', 'methods' => ['PUT'], 'controller' => 'probe::body', 'env' => null, 'requirements' => []],
            'probeBody' => ['path' => '/api/probe-body', 'methods' => ['POST'], 'controller' => 'probe::bodyProbe', 'env' => null, 'requirements' => []],
            'overridden' => ['path' => '/api/overridden/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::item', 'env' => null, 'requirements' => []],
            'range' => ['path' => '/api/range', 'methods' => ['GET'], 'controller' => 'ctrl::range', 'env' => null, 'requirements' => []],
            'guarded' => ['path' => '/api/guarded', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => ['q' => '\d+']],
            'dev' => ['path' => '/api/dev', 'methods' => ['GET'], 'controller' => 'ctrl::dev', 'env' => 'Development', 'requirements' => []],
            'denied' => ['path' => '/api/denied', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'header' => ['path' => '/api/header', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
            'token' => ['path' => '/api/token', 'methods' => ['POST'], 'controller' => 'ctrl::token', 'env' => null, 'requirements' => []],
            'cached' => ['path' => '/api/cached', 'methods' => ['GET'], 'controller' => 'ctrl::cached', 'env' => null, 'requirements' => []],
            'limited' => ['path' => '/api/limited', 'methods' => ['GET'], 'controller' => 'ctrl::limited', 'env' => null, 'requirements' => []],
            'problem' => ['path' => '/api/problem', 'methods' => ['GET'], 'controller' => 'ctrl::problem', 'env' => null, 'requirements' => []],
            'entity' => ['path' => '/api/entity/{item}', 'methods' => ['GET'], 'controller' => 'entityCtrl::show', 'env' => null, 'requirements' => []],
            'scopedSite' => ['path' => '/api/scoped-site', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'sites' => ['main']],
            'scopedSiteOther' => ['path' => '/api/scoped-site-other', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'sites' => ['other-site']],
            'scopedLanguage' => ['path' => '/api/scoped-language', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'languages' => [0]],
            'scopedLanguageOther' => ['path' => '/api/scoped-language-other', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => [], 'languages' => [1]],
        ];

        /** @var array<string, array{lifetime: int, tags: list<string>, ignoreParams: list<string>}> $cacheConfigs */
        $cacheConfigs = [
            'cached' => ['lifetime' => 3600, 'tags' => ['pages'], 'ignoreParams' => []],
        ];

        /** @var array<string, array{limit: int, interval: string, policy: string, keyBy: string}> $rateLimits */
        $rateLimits = [
            'limited' => ['limit' => 1, 'interval' => '1 minute', 'policy' => 'sliding_window', 'keyBy' => 'ip'],
        ];

        /** @var array<string, list<array{service: string, options: array<string, mixed>}>> $authenticators */
        $authenticators = [
            'denied' => [['service' => DenyAuthenticator::class, 'options' => []]],
            'header' => [['service' => HeaderAuthenticator::class, 'options' => []]],
        ];

        /** @var array<string, string> $requestTokenScopes */
        $requestTokenScopes = [
            'token' => 'routing/example-token',
        ];

        $request = ['name' => 'request', 'type' => null, 'source' => 'request', 'nullable' => false, 'hasDefault' => false, 'default' => null];

        /** @var array<string, list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>> $arguments */
        $arguments = [
            'count' => [],
            'item' => [['name' => 'id', 'type' => 'int', 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null]],
            'blog' => [['name' => 'page', 'type' => 'int', 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null]],
            'search' => [['name' => 'q', 'type' => 'int', 'source' => 'input', 'nullable' => false, 'hasDefault' => false, 'default' => null]],
            'tags' => [['name' => 'tags', 'type' => 'string', 'source' => 'variadic', 'nullable' => false, 'hasDefault' => false, 'default' => null]],
            'aliased' => [['name' => 'q', 'type' => 'string', 'source' => 'input', 'nullable' => false, 'hasDefault' => false, 'default' => null]],
            'submit' => [$request],
            'restricted' => [$request],
            'probe' => [$request],
            'coins' => [$request],
            'body' => [
                ['name' => 'title', 'type' => 'string', 'source' => 'body', 'nullable' => false, 'hasDefault' => true, 'default' => 'none'],
                ['name' => 'priority', 'type' => 'int', 'source' => 'body', 'nullable' => false, 'hasDefault' => true, 'default' => 0],
            ],
            'putBody' => [
                ['name' => 'title', 'type' => 'string', 'source' => 'body', 'nullable' => false, 'hasDefault' => true, 'default' => 'none'],
                ['name' => 'priority', 'type' => 'int', 'source' => 'body', 'nullable' => false, 'hasDefault' => true, 'default' => 0],
            ],
            'probeBody' => [['name' => 'note', 'type' => 'string', 'source' => 'body', 'nullable' => false, 'hasDefault' => false, 'default' => null], $request],
            'overridden' => [['name' => 'id', 'type' => 'int', 'source' => 'query', 'nullable' => false, 'hasDefault' => false, 'default' => null]],
            'range' => [
                ['name' => 'from', 'type' => 'int', 'source' => 'input', 'nullable' => false, 'hasDefault' => false, 'default' => null],
                ['name' => 'to', 'type' => 'int', 'source' => 'input', 'nullable' => false, 'hasDefault' => true, 'default' => 10],
                ['name' => 'label', 'type' => 'string', 'source' => 'input', 'nullable' => true, 'hasDefault' => true, 'default' => null],
            ],
            'guarded' => [],
            'dev' => [],
            'denied' => [],
            'header' => [],
            'token' => [],
            'cached' => [],
            'limited' => [],
            'problem' => [],
            'entity' => [['name' => 'item', 'type' => Item::class, 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null]],
            'scopedSite' => [],
            'scopedSiteOther' => [],
            'scopedLanguage' => [],
            'scopedLanguageOther' => [],
        ];

        $locator = new ServiceLocator([
            'ctrl' => static fn (): ExampleController => new ExampleController(),
            'probe' => static fn (): InvokerProbeController => new InvokerProbeController(),
            'entityCtrl' => static fn (): EntityController => new EntityController(),
        ]);
        $authenticatorLocator = new ServiceLocator([
            PassAuthenticator::class => static fn (): PassAuthenticator => new PassAuthenticator(),
            DenyAuthenticator::class => static fn (): DenyAuthenticator => new DenyAuthenticator(),
            HeaderAuthenticator::class => static fn (): HeaderAuthenticator => new HeaderAuthenticator(),
        ]);

        return new RouteRegistry($routes, $locator, $cacheConfigs, $rateLimits, $arguments, $authenticators, $requestTokenScopes, $authenticatorLocator);
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

    private function request(): ServerRequest
    {
        $site = $this->site();

        return (new RequestBuilder('POST', 'https://example.com/mcp'))
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage())
            ->build();
    }

    private function requestWithHeader(string $name, string $value): ServerRequest
    {
        $site = $this->site();

        return (new RequestBuilder('POST', 'https://example.com/mcp'))
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage())
            ->withHeader($name, $value)
            ->build();
    }
}
