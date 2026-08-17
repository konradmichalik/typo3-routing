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

use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\Exception\{MethodNotAllowedException, ResourceNotFoundException};
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RequestContext;

/**
 * RouteRegistryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RouteRegistry::class)]
final class RouteRegistryTest extends TestCase
{
    #[Test]
    public function buildsRouteCollectionWithDefaultsAndMethods(): void
    {
        $collection = $this->createRegistry()->getRouteCollection();
        $route = $collection->get('fixture_count');

        self::assertNotNull($route);
        self::assertSame('/api/count', $route->getPath());
        self::assertSame(['GET'], $route->getMethods());
        self::assertSame('fixture_controller::count', $route->getDefault('_controller'));
        self::assertNull($route->getDefault('_env'));
        self::assertSame('Development', $collection->get('fixture_submit')?->getDefault('_env'));
    }

    #[Test]
    public function staticPrefixesStopAtTheFirstPlaceholder(): void
    {
        $prefixes = RouteRegistry::staticPrefixes($this->constrainedRegistry()->getRouteCollection());

        self::assertSame(['/api/item'], $prefixes);
    }

    /**
     * A leading placeholder leaves no static part at all. The empty string matches every path, so such
     * a route correctly disables the dispatcher's gate instead of hiding behind it.
     */
    #[Test]
    public function staticPrefixesAreEmptyStringsForRoutesStartingWithAPlaceholder(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'catch_all' => ['path' => '/{slug}', 'methods' => ['GET'], 'controller' => 'ctrl::show', 'env' => null, 'requirements' => []],
        ];

        $prefixes = RouteRegistry::staticPrefixes((new RouteRegistry($routes, new ServiceLocator([])))->getRouteCollection());

        self::assertSame([''], $prefixes);
    }

    /**
     * A path declared with a trailing slash also has to let its slashless form reach the matcher, which
     * tolerates the difference — so the gate keeps the shorter prefix next to the declared one.
     */
    #[Test]
    public function staticPrefixesCoverTheSlashlessFormOfAPathDeclaredWithATrailingSlash(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'slashed' => ['path' => '/api/slashed/', 'methods' => ['GET'], 'controller' => 'ctrl::show', 'env' => null, 'requirements' => []],
        ];

        $prefixes = RouteRegistry::staticPrefixes((new RouteRegistry($routes, new ServiceLocator([])))->getRouteCollection());

        self::assertSame(['/api/slashed/', '/api/slashed'], $prefixes);
    }

    /**
     * The root path is all slash, so trimming would leave the empty prefix. Keeping `/` loses nothing:
     * it already matches every path.
     */
    #[Test]
    public function staticPrefixesKeepTheRootPathAsItIs(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'root' => ['path' => '/', 'methods' => ['GET'], 'controller' => 'ctrl::show', 'env' => null, 'requirements' => []],
        ];

        $prefixes = RouteRegistry::staticPrefixes((new RouteRegistry($routes, new ServiceLocator([])))->getRouteCollection());

        self::assertSame(['/'], $prefixes);
    }

    #[Test]
    public function exposesTheStaticPrefixesBakedInAtCompileTime(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'fixture_count' => ['path' => '/api/count', 'methods' => ['GET'], 'controller' => 'ctrl::count', 'env' => null, 'requirements' => []],
        ];

        $registry = new RouteRegistry($routes, new ServiceLocator([]), staticPrefixes: ['/baked/']);

        self::assertSame(['/baked/'], $registry->getStaticPrefixes());
    }

    /**
     * Mirrors the getMatcher() fallback: a registry wired by hand carries no compiled data, and a gate
     * derived as empty would make the dispatcher drop every one of its routes.
     */
    #[Test]
    public function derivesStaticPrefixesWhenNoneWereBakedIn(): void
    {
        $registry = $this->createRegistry();

        self::assertSame(['/api/count', '/api/submit'], $registry->getStaticPrefixes());
    }

    #[Test]
    public function derivesNoStaticPrefixesWithoutAnyRoute(): void
    {
        self::assertSame([], (new RouteRegistry([], new ServiceLocator([])))->getStaticPrefixes());
    }

    #[Test]
    public function optedInRoutesGetAMatcherThatIgnoresCase(): void
    {
        $matcher = $this->caseInsensitiveRegistry()->getCaseInsensitiveMatcher($this->getContext());

        self::assertNotNull($matcher);
        self::assertSame('fixture_loose', $matcher->match('/API/Loose')['_route']);
    }

    #[Test]
    public function theCaseInsensitiveMatcherKeepsPlaceholderValuesUntouched(): void
    {
        $matcher = $this->caseInsensitiveRegistry()->getCaseInsensitiveMatcher($this->getContext());

        self::assertNotNull($matcher);
        self::assertSame('MySlug', $matcher->match('/API/Loose/MySlug')['slug']);
    }

    #[Test]
    public function theCaseInsensitiveMatcherHoldsOnlyTheOptedInRoutes(): void
    {
        $matcher = $this->caseInsensitiveRegistry()->getCaseInsensitiveMatcher($this->getContext());

        self::assertNotNull($matcher);
        $this->expectException(ResourceNotFoundException::class);

        $matcher->match('/api/strict');
    }

    #[Test]
    public function aRegistryWithoutAnyOptedInRouteHasNoCaseInsensitiveMatcher(): void
    {
        self::assertNull($this->createRegistry()->getCaseInsensitiveMatcher($this->getContext()));
    }

    /**
     * The collection is memoised, so a second request must be served from it rather than rebuilt.
     */
    #[Test]
    public function servesTheCaseInsensitiveMatcherOnEveryCall(): void
    {
        $registry = $this->caseInsensitiveRegistry();

        $first = $registry->getCaseInsensitiveMatcher($this->getContext());
        $second = $registry->getCaseInsensitiveMatcher($this->getContext());

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame('fixture_loose', $first->match('/API/Loose')['_route']);
        self::assertSame('fixture_loose', $second->match('/api/LOOSE')['_route']);
    }

    #[Test]
    public function derivesTheCaseInsensitivePrefixesFromTheOptedInRoutesOnly(): void
    {
        self::assertSame(['/api/loose'], $this->caseInsensitiveRegistry()->getCaseInsensitivePrefixes());
    }

    #[Test]
    public function exposesTheCaseInsensitivePrefixesBakedInAtCompileTime(): void
    {
        $registry = new RouteRegistry([], new ServiceLocator([]), caseInsensitivePrefixes: ['/baked/']);

        self::assertSame(['/baked/'], $registry->getCaseInsensitivePrefixes());
    }

    #[Test]
    public function matcherResolvesAKnownPath(): void
    {
        $context = new RequestContext();
        $context->setMethod('GET');

        $match = $this->createRegistry()->getMatcher($context)->match('/api/count');

        self::assertSame('fixture_controller::count', $match['_controller']);
        self::assertSame('fixture_count', $match['_route']);
    }

    #[Test]
    public function matcherThrowsForUnknownPath(): void
    {
        $context = new RequestContext();
        $context->setMethod('GET');

        $this->expectException(ResourceNotFoundException::class);

        $this->createRegistry()->getMatcher($context)->match('/api/unknown');
    }

    #[Test]
    public function matcherThrowsForDisallowedMethod(): void
    {
        $context = new RequestContext();
        $context->setMethod('GET');

        $this->expectException(MethodNotAllowedException::class);

        $this->createRegistry()->getMatcher($context)->match('/api/submit');
    }

    #[Test]
    public function matcherMatchesPathSatisfyingRequirement(): void
    {
        $context = new RequestContext();
        $context->setMethod('GET');

        $match = $this->constrainedRegistry()->getMatcher($context)->match('/api/item/42');

        self::assertSame('fixture_controller::item', $match['_controller']);
        self::assertSame('42', $match['id']);
    }

    #[Test]
    public function matcherRejectsPathViolatingRequirement(): void
    {
        $context = new RequestContext();
        $context->setMethod('GET');

        $this->expectException(ResourceNotFoundException::class);

        $this->constrainedRegistry()->getMatcher($context)->match('/api/item/abc');
    }

    #[Test]
    public function higherPriorityRouteMatchesFirstOnOverlap(): void
    {
        // The placeholder route is registered first, so insertion order alone would let it win; the
        // static route only takes precedence because of its higher priority.
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int}> $routes */
        $routes = [
            'item_show' => ['path' => '/api/item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::show', 'env' => null, 'requirements' => [], 'priority' => 0],
            'item_new' => ['path' => '/api/item/new', 'methods' => ['GET'], 'controller' => 'ctrl::new', 'env' => null, 'requirements' => [], 'priority' => 10],
        ];
        $registry = new RouteRegistry($routes, new ServiceLocator([]));

        $context = new RequestContext();
        $context->setMethod('GET');

        self::assertSame('item_new', $registry->getMatcher($context)->match('/api/item/new')['_route']);
        self::assertSame('item_show', $registry->getMatcher($context)->match('/api/item/42')['_route']);
    }

    #[Test]
    public function matcherEnforcesRouteSchemes(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, schemes?: list<string>}> $routes */
        $routes = [
            'secure' => ['path' => '/api/secure', 'methods' => ['GET'], 'controller' => 'ctrl::secure', 'env' => null, 'requirements' => [], 'schemes' => ['https']],
        ];
        $registry = new RouteRegistry($routes, new ServiceLocator([]));

        $httpsContext = new RequestContext();
        $httpsContext->setMethod('GET');
        $httpsContext->setScheme('https');
        self::assertSame('secure', $registry->getMatcher($httpsContext)->match('/api/secure')['_route']);

        $httpContext = new RequestContext();
        $httpContext->setMethod('GET');
        $httpContext->setScheme('http');
        $this->expectException(ResourceNotFoundException::class);
        $registry->getMatcher($httpContext)->match('/api/secure');
    }

    #[Test]
    public function matcherEnforcesRouteHost(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, host?: string|null}> $routes */
        $routes = [
            'tenant' => ['path' => '/api/tenant', 'methods' => ['GET'], 'controller' => 'ctrl::tenant', 'env' => null, 'requirements' => [], 'host' => 'api.example.com'],
        ];
        $registry = new RouteRegistry($routes, new ServiceLocator([]));

        $matchingContext = new RequestContext();
        $matchingContext->setMethod('GET');
        $matchingContext->setHost('api.example.com');
        self::assertSame('tenant', $registry->getMatcher($matchingContext)->match('/api/tenant')['_route']);

        $otherContext = new RequestContext();
        $otherContext->setMethod('GET');
        $otherContext->setHost('other.example.com');
        $this->expectException(ResourceNotFoundException::class);
        $registry->getMatcher($otherContext)->match('/api/tenant');
    }

    #[Test]
    public function exposesParamDescriptionsPerRouteAndEmptyForUnknownRoutes(): void
    {
        $registry = new RouteRegistry([], new ServiceLocator([]), paramDescriptions: ['blog' => ['page' => 'Page number.']]);

        self::assertSame(['page' => 'Page number.'], $registry->getParamDescriptions('blog'));
        self::assertSame([], $registry->getParamDescriptions('unknown'));
    }

    #[Test]
    public function exposesOptionalInputsPerRouteAndEmptyForUnknownRoutes(): void
    {
        $registry = new RouteRegistry([], new ServiceLocator([]), optionalInputs: ['blog' => ['page']]);

        self::assertSame(['page'], $registry->getOptionalInputs('blog'));
        self::assertSame([], $registry->getOptionalInputs('unknown'));
    }

    #[Test]
    public function bakesUserDefaultsIntoTheRouteAlongsideInternalKeys(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, defaults?: array<string, mixed>}> $routes */
        $routes = [
            'blog' => ['path' => '/api/blog/{page}', 'methods' => ['GET'], 'controller' => 'ctrl::blog', 'env' => null, 'requirements' => [], 'defaults' => ['page' => 1]],
        ];
        $route = (new RouteRegistry($routes, new ServiceLocator([])))->getRouteCollection()->get('blog');

        self::assertNotNull($route);
        self::assertSame(1, $route->getDefault('page'));
        // Internal metadata is preserved next to the user default.
        self::assertSame('ctrl::blog', $route->getDefault('_controller'));
    }

    #[Test]
    public function trailingPlaceholderWithADefaultBecomesOptional(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, defaults?: array<string, mixed>}> $routes */
        $routes = [
            'blog' => ['path' => '/api/blog/{page}', 'methods' => ['GET'], 'controller' => 'ctrl::blog', 'env' => null, 'requirements' => [], 'defaults' => ['page' => 1]],
        ];
        $registry = new RouteRegistry($routes, new ServiceLocator([]));

        $context = new RequestContext();
        $context->setMethod('GET');

        // The shorter path matches and yields the default; the explicit segment wins over it.
        self::assertSame(1, $registry->getMatcher($context)->match('/api/blog')['page']);
        self::assertSame('5', $registry->getMatcher($context)->match('/api/blog/5')['page']);
    }

    #[Test]
    public function usesCompiledMatcherWhenCompiledRoutesAreBaked(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int}> $routes */
        $routes = [
            'item_show' => ['path' => '/api/item/{id}', 'methods' => ['GET'], 'controller' => 'ctrl::show', 'env' => null, 'requirements' => ['id' => '\d+'], 'priority' => 0],
            'item_new' => ['path' => '/api/item/new', 'methods' => ['GET'], 'controller' => 'ctrl::new', 'env' => null, 'requirements' => [], 'priority' => 10],
        ];
        $compiled = (new CompiledUrlMatcherDumper(RouteRegistry::buildCollection($routes)))->getCompiledRoutes();
        $registry = new RouteRegistry($routes, new ServiceLocator([]), compiledRoutes: $compiled);

        $context = new RequestContext();
        $context->setMethod('GET');
        $matcher = $registry->getMatcher($context);

        self::assertInstanceOf(CompiledUrlMatcher::class, $matcher);
        // The compiled matcher behaves exactly like the plain one: priority, placeholders, requirements.
        self::assertSame('item_new', $matcher->match('/api/item/new')['_route']);
        self::assertSame('42', $matcher->match('/api/item/42')['id']);
        $this->expectException(ResourceNotFoundException::class);
        $matcher->match('/api/item/abc');
    }

    #[Test]
    public function exposesCacheConfigPerRouteName(): void
    {
        $registry = new RouteRegistry(
            [],
            new ServiceLocator([]),
            ['cached' => ['lifetime' => 60, 'tags' => ['pages'], 'ignoreParams' => ['search']]],
        );

        $config = $registry->getCacheConfig('cached');

        self::assertNotNull($config);
        self::assertSame(60, $config['lifetime']);
        self::assertSame(['pages'], $config['tags']);
        self::assertNull($registry->getCacheConfig('uncached'));
    }

    #[Test]
    public function exposesRateLimitPerRouteName(): void
    {
        $registry = new RouteRegistry(
            [],
            new ServiceLocator([]),
            [],
            ['limited' => ['limit' => 60, 'interval' => '1 minute', 'policy' => 'sliding_window', 'keyBy' => 'ip']],
        );

        $rateLimit = $registry->getRateLimit('limited');

        self::assertNotNull($rateLimit);
        self::assertSame(60, $rateLimit['limit']);
        self::assertSame('1 minute', $rateLimit['interval']);
        self::assertSame('sliding_window', $rateLimit['policy']);
        self::assertSame('ip', $rateLimit['keyBy']);
        self::assertNull($registry->getRateLimit('unlimited'));
    }

    #[Test]
    public function exposesCorsConfigPerRouteName(): void
    {
        $registry = new RouteRegistry(
            [],
            new ServiceLocator([]),
            corsConfigs: ['restricted' => ['allowedOrigins' => ['https://app.example.com'], 'allowedHeaders' => 'Content-Type', 'allowCredentials' => true, 'exposeHeaders' => '', 'maxAge' => 600]],
        );

        $cors = $registry->getCorsConfig('restricted');

        self::assertNotNull($cors);
        self::assertSame(['https://app.example.com'], $cors['allowedOrigins']);
        self::assertTrue($cors['allowCredentials']);
        self::assertSame(600, $cors['maxAge']);
        self::assertNull($registry->getCorsConfig('unrestricted'));
    }

    #[Test]
    public function exposesArgumentSpecsPerRouteName(): void
    {
        $spec = ['name' => 'id', 'type' => 'int', 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null];
        $registry = new RouteRegistry([], new ServiceLocator([]), [], [], ['item' => [$spec]]);

        self::assertSame([$spec], $registry->getArguments('item'));
        // Routes without recorded specs report an empty argument list.
        self::assertSame([], $registry->getArguments('unknown'));
    }

    #[Test]
    public function exposesRawRoutesAndControllerLocator(): void
    {
        $locator = new ServiceLocator([]);
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = ['x' => ['path' => '/x', 'methods' => ['GET'], 'controller' => 'a::b', 'env' => null, 'requirements' => []]];
        $registry = new RouteRegistry($routes, $locator);

        self::assertArrayHasKey('x', $registry->getRoutes());
        self::assertSame($locator, $registry->getControllerLocator());
    }

    #[Test]
    public function exposesAuthenticatorsPerRouteName(): void
    {
        $authenticators = ['secure' => [['service' => 'auth_a', 'options' => ['role' => 'admin']]]];
        $registry = new RouteRegistry([], new ServiceLocator([]), [], [], [], $authenticators);

        self::assertSame($authenticators['secure'], $registry->getAuthenticators('secure'));
        // A route without authenticators is public.
        self::assertSame([], $registry->getAuthenticators('public'));
    }

    #[Test]
    public function exposesRequestTokenScopePerRouteName(): void
    {
        $registry = new RouteRegistry([], new ServiceLocator([]), [], [], [], [], ['token' => 'routing/token']);

        self::assertSame('routing/token', $registry->getRequestTokenScope('token'));
        self::assertNull($registry->getRequestTokenScope('open'));
    }

    #[Test]
    public function exposesTheAuthenticatorLocatorWhenProvided(): void
    {
        $authenticatorLocator = new ServiceLocator([]);
        $registry = new RouteRegistry([], new ServiceLocator([]), [], [], [], [], [], $authenticatorLocator);

        self::assertSame($authenticatorLocator, $registry->getAuthenticatorLocator());
    }

    #[Test]
    public function throwsWhenTheAuthenticatorLocatorIsMissing(): void
    {
        $registry = new RouteRegistry([], new ServiceLocator([]));

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000021);

        $registry->getAuthenticatorLocator();
    }

    private function caseInsensitiveRegistry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, caseInsensitive?: bool}> $routes */
        $routes = [
            'fixture_strict' => ['path' => '/api/strict', 'methods' => ['GET'], 'controller' => 'ctrl::strict', 'env' => null, 'requirements' => []],
            'fixture_loose' => ['path' => '/api/loose', 'methods' => ['GET'], 'controller' => 'ctrl::loose', 'env' => null, 'requirements' => [], 'caseInsensitive' => true],
            'fixture_loose_item' => ['path' => '/api/loose/{slug}', 'methods' => ['GET'], 'controller' => 'ctrl::looseItem', 'env' => null, 'requirements' => [], 'caseInsensitive' => true],
        ];

        return new RouteRegistry($routes, new ServiceLocator([]));
    }

    private function getContext(): RequestContext
    {
        $context = new RequestContext();
        $context->setMethod('GET');

        return $context;
    }

    private function createRegistry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'fixture_count' => [
                'path' => '/api/count',
                'methods' => ['GET'],
                'controller' => 'fixture_controller::count',
                'env' => null,
                'requirements' => [],
            ],
            'fixture_submit' => [
                'path' => '/api/submit',
                'methods' => ['POST'],
                'controller' => 'fixture_controller::submit',
                'env' => 'Development',
                'requirements' => [],
            ],
        ];

        return new RouteRegistry($routes, new ServiceLocator([]));
    }

    private function constrainedRegistry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'fixture_item' => [
                'path' => '/api/item/{id}',
                'methods' => ['GET'],
                'controller' => 'fixture_controller::item',
                'env' => null,
                'requirements' => ['id' => '\d+'],
            ],
        ];

        return new RouteRegistry($routes, new ServiceLocator([]));
    }
}
