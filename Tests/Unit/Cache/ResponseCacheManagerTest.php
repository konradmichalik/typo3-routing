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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Cache;

use KonradMichalik\Ttt\Http\{RequestBuilder, Requests};
use KonradMichalik\Typo3Routing\Cache\ResponseCacheManager;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\CreatesResponseCacheManager;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Entity\Item;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Enum\Priority;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\{Response, ServerRequest};
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * ResponseCacheManagerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(ResponseCacheManager::class)]
final class ResponseCacheManagerTest extends TestCase
{
    use CreatesResponseCacheManager;

    private ResponseCacheManager $subject;

    protected function setUp(): void
    {
        $this->subject = $this->createResponseCacheManager();
    }

    #[Test]
    public function storesAndRetrievesStatusHeadersAndBody(): void
    {
        $response = new Response('php://temp', 201, ['Content-Type' => 'application/json', 'X-Foo' => 'bar']);
        $response->getBody()->write('{"ok":true}');

        $this->subject->store('route_a', $response, 3600, ['pages']);
        $cached = $this->subject->get('route_a');

        self::assertNotNull($cached);
        self::assertSame(201, $cached->getStatusCode());
        self::assertSame('application/json', $cached->getHeaderLine('Content-Type'));
        self::assertSame('bar', $cached->getHeaderLine('X-Foo'));
        self::assertSame('{"ok":true}', (string) $cached->getBody());
    }

    #[Test]
    public function returnsNullOnCacheMiss(): void
    {
        self::assertNull($this->subject->get('route_missing'));
    }

    #[Test]
    public function doesNotCacheSetCookieHeader(): void
    {
        $response = new Response('php://temp', 200, ['Set-Cookie' => 'sid=abc']);
        $this->subject->store('route_cookie', $response, 60, []);

        self::assertSame('', $this->subject->get('route_cookie')?->getHeaderLine('Set-Cookie'));
    }

    #[Test]
    public function flushByTagsRemovesEveryEntryTaggedWithOneOfThem(): void
    {
        $this->subject->store('route_news', new Response('php://temp', 200), 3600, ['tx_news']);
        $this->subject->store('route_teaser', new Response('php://temp', 200), 3600, ['teaser']);
        $this->subject->store('route_other', new Response('php://temp', 200), 3600, ['other']);

        $this->subject->flushByTags(['tx_news', 'teaser']);

        self::assertNull($this->subject->get('route_news'));
        self::assertNull($this->subject->get('route_teaser'));
        self::assertNotNull($this->subject->get('route_other'));
    }

    #[Test]
    public function withETagAddsAStrongBodyHashAndIsIdempotent(): void
    {
        $response = new Response('php://temp', 200);
        $response->getBody()->write('{"ok":true}');

        $tagged = $this->subject->withETag($response);
        $etag = $tagged->getHeaderLine('ETag');

        self::assertSame('"'.hash('sha256', '{"ok":true}').'"', $etag);
        // The body stays readable after hashing.
        self::assertSame('{"ok":true}', (string) $tagged->getBody());
        // An existing ETag is preserved untouched.
        self::assertSame($etag, $this->subject->withETag($tagged)->getHeaderLine('ETag'));
    }

    #[Test]
    public function withCacheStatusAddsTheHeaderForACacheableGetRoute(): void
    {
        $cacheConfig = ['lifetime' => 3600, 'tags' => [], 'ignoreParams' => []];
        $response = $this->subject->withCacheStatus(new Response('php://temp', 200), $cacheConfig, Requests::get('https://example.com/api/x')->build(), 'HIT');

        self::assertSame('HIT', $response->getHeaderLine('X-TYPO3-API-Cache'));
    }

    #[Test]
    public function withCacheStatusOmitsTheHeaderWhenCachingIsNotConfigured(): void
    {
        $response = $this->subject->withCacheStatus(new Response('php://temp', 200), null, Requests::get('https://example.com/api/x')->build(), 'MISS');

        self::assertSame('', $response->getHeaderLine('X-TYPO3-API-Cache'));
    }

    #[Test]
    public function withCacheStatusOmitsTheHeaderForNonGetRequests(): void
    {
        $cacheConfig = ['lifetime' => 3600, 'tags' => [], 'ignoreParams' => []];
        $response = $this->subject->withCacheStatus(new Response('php://temp', 200), $cacheConfig, Requests::post('https://example.com/api/x')->build(), 'MISS');

        self::assertSame('', $response->getHeaderLine('X-TYPO3-API-Cache'));
    }

    #[Test]
    public function buildKeyIsStableAndHonoursIgnoredParameters(): void
    {
        $arguments = [self::argument('page', 'input'), self::argument('request', 'request')];
        $base = $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '1', 'search' => 'foo']), ['search'], $arguments);
        $otherSearch = $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '1', 'search' => 'bar']), ['search'], $arguments);
        $otherPage = $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '2', 'search' => 'foo']), ['search'], $arguments);

        self::assertSame($base, $otherSearch);
        self::assertNotSame($base, $otherPage);
    }

    #[Test]
    public function buildKeyIgnoresAnUndeclaredQueryParameterOnARouteWithoutARequestArgument(): void
    {
        $arguments = [self::argument('page', 'input')];
        $base = $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '1', 'x' => '1']), [], $arguments);
        $otherUndeclared = $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '1', 'x' => '2']), [], $arguments);
        $withoutUndeclared = $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '1']), [], $arguments);

        self::assertSame($base, $otherUndeclared);
        self::assertSame($base, $withoutUndeclared);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function argumentSources(): iterable
    {
        yield 'query' => ['query', true];
        yield 'input' => ['input', true];
        yield 'variadic' => ['variadic', true];
        yield 'body' => ['body', false];
        yield 'path' => ['path', false];
        yield 'upload' => ['upload', false];
    }

    #[Test]
    #[DataProvider('argumentSources')]
    public function buildKeyVariesOnlyOnTheWireKeyOfAnArgumentReadFromTheQuery(string $source, bool $varies): void
    {
        // The spec name is the wire key: #[Param(name: 'q')] on $term is stored as "q".
        $arguments = [self::argument('q', $source)];
        $one = $this->subject->buildKey(self::match(), $this->requestWithQuery(['q' => '1']), [], $arguments);
        $two = $this->subject->buildKey(self::match(), $this->requestWithQuery(['q' => '2']), [], $arguments);

        self::assertSame($varies, $one !== $two);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, array<string, mixed>}>
     */
    public static function equivalentInputs(): iterable
    {
        yield 'int with leading zero' => ['int', ['v' => '1'], ['v' => '01']];
        yield 'int with many leading zeros' => ['int', ['v' => '1'], ['v' => '0001']];
        yield 'bool 1 and true' => ['bool', ['v' => '1'], ['v' => 'true']];
        yield 'bool false spellings' => ['bool', ['v' => 'no'], ['v' => '0']];
        yield 'bool empty is false' => ['bool', ['v' => ''], ['v' => 'off']];
        yield 'float with trailing zero' => ['float', ['v' => '1.5'], ['v' => '1.50']];
        yield 'array passthrough' => ['array', ['v' => ['a' => 1]], ['v' => ['a' => 1]]];
        yield 'int backed enum' => [Priority::class, ['v' => '1'], ['v' => '1']];
    }

    /**
     * @param array<string, mixed> $first
     * @param array<string, mixed> $second
     */
    #[Test]
    #[DataProvider('equivalentInputs')]
    public function buildKeySharesOneEntryForValuesTheControllerReceivesIdentically(string $type, array $first, array $second): void
    {
        $arguments = [self::argument('v', 'query', $type, false)];

        self::assertSame(
            $this->subject->buildKey(self::match(), $this->requestWithQuery($first), [], $arguments),
            $this->subject->buildKey(self::match(), $this->requestWithQuery($second), [], $arguments),
        );
    }

    #[Test]
    public function buildKeyStillSeparatesValuesThatCoerceApart(): void
    {
        $arguments = [self::argument('page', 'query', 'int', false)];

        self::assertNotSame(
            $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '1']), [], $arguments),
            $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '2']), [], $arguments),
        );
    }

    #[Test]
    public function buildKeyKeysAMissingAndAnEmptyValueOnTheDefault(): void
    {
        $arguments = [self::argument('page', 'query', 'int', false, true, 1)];
        $explicit = $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '1']), [], $arguments);

        self::assertSame($explicit, $this->subject->buildKey(self::match(), $this->requestWithQuery([]), [], $arguments));
        self::assertSame($explicit, $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '']), [], $arguments));
        self::assertNotSame($explicit, $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '2']), [], $arguments));
    }

    #[Test]
    public function buildKeyKeysAPathPlaceholderInItsCoercedForm(): void
    {
        $arguments = [self::argument('id', 'path', 'int', false)];

        self::assertSame(
            $this->subject->buildKey(self::match(['id' => '5']), $this->requestWithQuery([]), [], $arguments),
            $this->subject->buildKey(self::match(['id' => '05']), $this->requestWithQuery([]), [], $arguments),
        );
    }

    #[Test]
    public function buildKeyCoercesAVariadicElementWise(): void
    {
        $arguments = [self::argument('ids', 'variadic', 'int', false)];

        self::assertSame(
            $this->subject->buildKey(self::match(), $this->requestWithQuery(['ids' => ['1', '2']]), [], $arguments),
            $this->subject->buildKey(self::match(), $this->requestWithQuery(['ids' => ['01', '002']]), [], $arguments),
        );
        self::assertNotSame(
            $this->subject->buildKey(self::match(), $this->requestWithQuery(['ids' => ['1', '2']]), [], $arguments),
            $this->subject->buildKey(self::match(), $this->requestWithQuery(['ids' => ['1']]), [], $arguments),
        );
    }

    /**
     * @return iterable<string, array{string|null, bool, array<string, mixed>}>
     */
    public static function rejectedInputs(): iterable
    {
        yield 'malformed int' => ['int', false, ['v' => 'abc']];
        yield 'malformed bool' => ['bool', false, ['v' => 'maybe']];
        yield 'unknown enum case' => [Priority::class, false, ['v' => '99']];
        yield 'malformed entity identifier' => [Item::class, false, ['v' => 'abc']];
        yield 'missing required value' => ['int', false, []];
        yield 'array for a scalar' => ['int', false, ['v' => ['1']]];
    }

    /**
     * @param array<string, mixed> $query
     */
    #[Test]
    #[DataProvider('rejectedInputs')]
    public function buildKeyReturnsNullWhenAConsumedValueWouldBeRejected(?string $type, bool $nullable, array $query): void
    {
        $arguments = [self::argument('v', 'query', $type, $nullable)];

        self::assertNull($this->subject->buildKey(self::match(), $this->requestWithQuery($query), [], $arguments));
    }

    #[Test]
    public function buildKeyReturnsNullWhenOneOfSeveralValuesIsRejected(): void
    {
        $arguments = [self::argument('a', 'query', 'int', false), self::argument('b', 'query', 'int', false)];

        self::assertNull($this->subject->buildKey(self::match(), $this->requestWithQuery(['a' => '1', 'b' => 'x']), [], $arguments));
    }

    #[Test]
    public function buildKeyNeverLoadsAnEntityAndKeysItOnItsIdentifier(): void
    {
        $persistenceManager = $this->createMock(PersistenceManagerInterface::class);
        $persistenceManager->expects(self::never())->method('getObjectByIdentifier');
        $subject = $this->createResponseCacheManager($persistenceManager);
        $arguments = [self::argument('item', 'query', Item::class, false)];

        $one = $subject->buildKey(self::match(), $this->requestWithQuery(['item' => '5']), [], $arguments);

        self::assertSame($one, $subject->buildKey(self::match(), $this->requestWithQuery(['item' => '05']), [], $arguments));
        self::assertNotSame($one, $subject->buildKey(self::match(), $this->requestWithQuery(['item' => '6']), [], $arguments));
    }

    #[Test]
    public function buildKeyKeepsRawInputWhenTheControllerTakesTheRequest(): void
    {
        $arguments = [self::argument('request', 'request'), self::argument('page', 'query', 'int', false)];

        self::assertNotSame(
            $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '1']), [], $arguments),
            $this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => '01']), [], $arguments),
        );
        self::assertNotNull($this->subject->buildKey(self::match(), $this->requestWithQuery(['page' => 'abc']), [], $arguments));
    }

    #[Test]
    public function buildKeyLeavesAnUploadArgumentOutOfTheKey(): void
    {
        $arguments = [self::argument('file', 'upload', null, false)];

        self::assertNotNull($this->subject->buildKey(self::match(), $this->requestWithQuery([]), [], $arguments));
    }

    #[Test]
    public function buildKeyVariesOnTheFullQueryWhenTheControllerTakesTheRequest(): void
    {
        $arguments = [self::argument('request', 'request')];
        $base = $this->subject->buildKey(self::match(), $this->requestWithQuery(['x' => '1', 'utm' => 'a']), ['utm'], $arguments);
        $otherUndeclared = $this->subject->buildKey(self::match(), $this->requestWithQuery(['x' => '2', 'utm' => 'a']), ['utm'], $arguments);
        $otherIgnored = $this->subject->buildKey(self::match(), $this->requestWithQuery(['x' => '1', 'utm' => 'b']), ['utm'], $arguments);

        self::assertNotSame($base, $otherUndeclared);
        self::assertSame($base, $otherIgnored);
    }

    #[Test]
    public function buildKeyIsIndependentOfTheQueryParameterOrder(): void
    {
        $arguments = [self::argument('request', 'request')];
        $ab = $this->subject->buildKey(self::match(), $this->requestWithQuery(['a' => '1', 'b' => '2']), [], $arguments);
        $ba = $this->subject->buildKey(self::match(), $this->requestWithQuery(['b' => '2', 'a' => '1']), [], $arguments);

        self::assertSame($ab, $ba);
    }

    #[Test]
    public function buildKeySharesTheEntryAcrossPathVariantsOfOneMatch(): void
    {
        $declared = $this->subject->buildKey(self::match(), Requests::get('https://example.com/api/x')->build(), [], []);
        $trailingSlash = $this->subject->buildKey(self::match(), Requests::get('https://example.com/api/x/')->build(), [], []);
        $leadingSlashes = $this->subject->buildKey(self::match(), Requests::get('https://example.com//api/x')->build(), [], []);
        $case = $this->subject->buildKey(self::match(), Requests::get('https://example.com/API/X')->build(), [], []);

        self::assertSame($declared, $trailingSlash);
        self::assertSame($declared, $leadingSlashes);
        self::assertSame($declared, $case);
    }

    #[Test]
    public function buildKeyVariesByRouteAndPlaceholderValueButNotByMetaKeys(): void
    {
        $arguments = [self::argument('id', 'path')];
        $base = $this->subject->buildKey(self::match(['id' => '1']), Requests::get('https://example.com/api/x/1')->build(), [], $arguments);
        $otherValue = $this->subject->buildKey(self::match(['id' => '2']), Requests::get('https://example.com/api/x/2')->build(), [], $arguments);
        $otherRoute = $this->subject->buildKey(['_route' => 'other', 'id' => '1'], Requests::get('https://example.com/api/x/1')->build(), [], $arguments);
        $extraMeta = $this->subject->buildKey(self::match(['id' => '1', '_canonicalVariant' => true]), Requests::get('https://example.com/api/x/1/')->build(), [], $arguments);

        self::assertNotSame($base, $otherValue);
        self::assertNotSame($base, $otherRoute);
        self::assertSame($base, $extraMeta);
    }

    #[Test]
    public function buildKeyVariesOnAnUnderscorePrefixedPlaceholder(): void
    {
        $arguments = [self::argument('_id', 'path')];
        $one = $this->subject->buildKey(self::match(['_id' => '1']), Requests::get('https://example.com/api/items/1')->build(), [], $arguments);
        $two = $this->subject->buildKey(self::match(['_id' => '2']), Requests::get('https://example.com/api/items/2')->build(), [], $arguments);

        self::assertNotSame($one, $two);
    }

    #[Test]
    public function buildKeyVariesOnABodyValueTheResolverPrefersOverTheQuery(): void
    {
        // The resolver merges query and body for "input", the body wins: a GET with a JSON body must not
        // be stored under the key of its query value.
        $arguments = [self::argument('id', 'input')];
        $queryOnly = $this->subject->buildKey(self::match(), Requests::get('https://example.com/api/x')->withQueryParams(['id' => '1'])->build(), [], $arguments);
        $bodyOverride = $this->subject->buildKey(self::match(), Requests::get('https://example.com/api/x')->withQueryParams(['id' => '1'])->withJsonBody(['id' => 5])->build(), [], $arguments);

        self::assertNotSame($queryOnly, $bodyOverride);
    }

    #[Test]
    public function buildKeyAcceptsInvalidUtf8AndNonFiniteValues(): void
    {
        $arguments = [self::argument('id', 'input')];
        $invalidUtf8 = $this->subject->buildKey(self::match(), $this->requestWithQuery(['id' => "\xff"]), [], $arguments);
        $otherBytes = $this->subject->buildKey(self::match(), $this->requestWithQuery(['id' => "\xfe"]), [], $arguments);
        $infinite = $this->subject->buildKey(self::match(), Requests::get('https://example.com/api/x')->withParsedBody(['id' => \INF])->build(), [], $arguments);

        self::assertNotSame($invalidUtf8, $otherBytes);
        self::assertIsString($infinite);
        self::assertStringStartsWith('route_', $infinite);
    }

    #[Test]
    public function buildKeyVariesOnABodyArgument(): void
    {
        $arguments = [self::argument('title', 'body')];
        $one = $this->subject->buildKey(self::match(), Requests::get('https://example.com/api/x')->withJsonBody(['title' => 'a'])->build(), [], $arguments);
        $two = $this->subject->buildKey(self::match(), Requests::get('https://example.com/api/x')->withJsonBody(['title' => 'b'])->build(), [], $arguments);

        self::assertNotSame($one, $two);
    }

    #[Test]
    public function buildKeyKeepsTheRawPathWhenTheControllerTakesTheRequest(): void
    {
        // The controller may read the URI itself (e.g. a self link), so its path variants stay apart.
        $arguments = [self::argument('request', 'request')];
        $declared = $this->subject->buildKey(self::match(), Requests::get('https://example.com/api/x')->build(), [], $arguments);
        $trailingSlash = $this->subject->buildKey(self::match(), Requests::get('https://example.com/api/x/')->build(), [], $arguments);

        self::assertNotSame($declared, $trailingSlash);
    }

    #[Test]
    public function buildKeyVariesBySiteOnTheSameHost(): void
    {
        // Two sites on one host with different base paths: the path is no longer in the key, the site is.
        $siteA = $this->subject->buildKey(self::match(), Requests::get('https://example.com/en/api/x')->withAttribute('site', new Site('en', 1, ['base' => '/en/']))->build(), [], []);
        $siteB = $this->subject->buildKey(self::match(), Requests::get('https://example.com/de/api/x')->withAttribute('site', new Site('de', 2, ['base' => '/de/']))->build(), [], []);

        self::assertNotSame($siteA, $siteB);
    }

    #[Test]
    public function buildKeyVariesByHost(): void
    {
        // Multi-site: the same route on two domains must never share a cache entry.
        $siteA = $this->subject->buildKey(self::match(), Requests::get('https://a.example.com/api/x')->build(), [], []);
        $siteB = $this->subject->buildKey(self::match(), Requests::get('https://b.example.com/api/x')->build(), [], []);

        self::assertNotSame($siteA, $siteB);
    }

    #[Test]
    public function buildKeySharesTheEntryBetweenHeadAndGet(): void
    {
        $get = $this->subject->buildKey(self::match(), Requests::get('https://example.com/api/x')->build(), [], []);
        $head = $this->subject->buildKey(self::match(), (new RequestBuilder('HEAD', 'https://example.com/api/x'))->build(), [], []);

        self::assertSame($get, $head);
    }

    #[Test]
    public function withCacheStatusAddsTheHeaderForACacheableHeadRoute(): void
    {
        $cacheConfig = ['lifetime' => 3600, 'tags' => [], 'ignoreParams' => []];
        $response = $this->subject->withCacheStatus(
            new Response('php://temp', 200),
            $cacheConfig,
            (new RequestBuilder('HEAD', 'https://example.com/api/x'))->build(),
            'HIT',
        );

        self::assertSame('HIT', $response->getHeaderLine('X-TYPO3-API-Cache'));
    }

    #[Test]
    public function cacheableMethodCanonicalisesHeadToGet(): void
    {
        self::assertSame('GET', $this->subject->cacheableMethod((new RequestBuilder('HEAD', 'https://example.com/api/x'))->build()));
        self::assertSame('POST', $this->subject->cacheableMethod(Requests::post('https://example.com/api/x')->build()));
    }

    /**
     * @param array<string, mixed> $placeholders
     *
     * @return array<string, mixed>
     */
    private static function match(array $placeholders = []): array
    {
        return ['_route' => 'r', ...$placeholders];
    }

    /**
     * @return array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}
     */
    private static function argument(string $name, string $source, ?string $type = null, bool $nullable = true, bool $hasDefault = false, mixed $default = null): array
    {
        return ['name' => $name, 'type' => $type, 'source' => $source, 'nullable' => $nullable, 'hasDefault' => $hasDefault, 'default' => $default];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function requestWithQuery(array $query): ServerRequest
    {
        return Requests::get('https://example.com/api/x')->withQueryParams($query)->build();
    }
}
