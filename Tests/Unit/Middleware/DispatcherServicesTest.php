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

use KonradMichalik\Typo3Routing\Authentication\AccessGuard;
use KonradMichalik\Typo3Routing\Cache\{CacheBypassGuard, ResponseCacheManager};
use KonradMichalik\Typo3Routing\Http\{CorsHandler, CorsPreflightResolver, DeprecationHeaders, RouteUrlGenerator, SiteBasePathResolver};
use KonradMichalik\Typo3Routing\Middleware\DispatcherServices;
use KonradMichalik\Typo3Routing\RateLimit\{ClientKeyResolver, RateLimitCheck, RateLimitEnforcer};
use KonradMichalik\Typo3Routing\Routing\{ControllerArgumentResolver, ControllerInvoker, RouteMatcher, RouteRegistry, SiteLanguageScope};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\CreatesResponseCacheManager;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * DispatcherServicesTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(DispatcherServices::class)]
final class DispatcherServicesTest extends TestCase
{
    use CreatesResponseCacheManager;

    /**
     * Every accessor, in one test: each is the same one-line delegation, and what is worth asserting is
     * that the facade hands back the service the locator was wired with rather than any instance of the
     * right class. The services are built for real because a locator is what the container gives the
     * dispatcher in production, and most of these classes are final and cannot be mocked anyway.
     */
    #[Test]
    public function eachAccessorReturnsTheServiceItWasWiredWith(): void
    {
        $registry = new RouteRegistry([], new ServiceLocator([]));
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $context = new Context();

        $matcher = new RouteMatcher($registry, $extensionConfiguration);
        $cache = $this->createResponseCacheManager();
        $rateLimitCheck = new RateLimitCheck(new RateLimitEnforcer(new InMemoryStorage()));
        $invoker = new ControllerInvoker($registry, new ControllerArgumentResolver($this->createMock(PersistenceManagerInterface::class)));
        $accessGuard = new AccessGuard($registry, $context);
        $cors = new CorsHandler($extensionConfiguration);
        $corsPreflight = new CorsPreflightResolver($registry, $matcher, $cors);
        $cacheBypass = new CacheBypassGuard($context);
        $clientKeyResolver = new ClientKeyResolver($context);
        $urlGenerator = new RouteUrlGenerator($registry, new SiteBasePathResolver());
        $siteLanguageScope = new SiteLanguageScope($this->createMock(SiteFinder::class), $this->createMock(LogManager::class));
        $deprecation = new DeprecationHeaders($registry, $urlGenerator);

        $services = new DispatcherServices(new ServiceLocator([
            RouteMatcher::class => static fn (): RouteMatcher => $matcher,
            ResponseCacheManager::class => static fn (): ResponseCacheManager => $cache,
            RateLimitCheck::class => static fn (): RateLimitCheck => $rateLimitCheck,
            ControllerInvoker::class => static fn (): ControllerInvoker => $invoker,
            AccessGuard::class => static fn (): AccessGuard => $accessGuard,
            CorsHandler::class => static fn (): CorsHandler => $cors,
            CorsPreflightResolver::class => static fn (): CorsPreflightResolver => $corsPreflight,
            CacheBypassGuard::class => static fn (): CacheBypassGuard => $cacheBypass,
            ClientKeyResolver::class => static fn (): ClientKeyResolver => $clientKeyResolver,
            RouteUrlGenerator::class => static fn (): RouteUrlGenerator => $urlGenerator,
            SiteLanguageScope::class => static fn (): SiteLanguageScope => $siteLanguageScope,
            DeprecationHeaders::class => static fn (): DeprecationHeaders => $deprecation,
        ]));

        self::assertSame($matcher, $services->matcher());
        self::assertSame($cache, $services->cache());
        self::assertSame($rateLimitCheck, $services->rateLimitCheck());
        self::assertSame($invoker, $services->invoker());
        self::assertSame($accessGuard, $services->accessGuard());
        self::assertSame($cors, $services->cors());
        self::assertSame($corsPreflight, $services->corsPreflight());
        self::assertSame($cacheBypass, $services->cacheBypass());
        self::assertSame($clientKeyResolver, $services->clientKeyResolver());
        self::assertSame($urlGenerator, $services->urlGenerator());
        self::assertSame($siteLanguageScope, $services->siteLanguageScope());
        self::assertSame($deprecation, $services->deprecation());
    }

    /**
     * The facade deliberately does not memoise: it delegates on every call and inherits whatever
     * sharing the container provides, which for a normal (shared) service definition means the same
     * instance every time. Worth pinning down, because the dispatcher calls several accessors more than
     * once per request, so a facade that cached independently would be a second source of truth about
     * instance identity for no gain — a container get() of an already-built service measures ~1 µs.
     */
    #[Test]
    public function accessorsDelegateOnEveryCallRatherThanCachingThemselves(): void
    {
        $calls = 0;
        $guard = new CacheBypassGuard(new Context());
        $locator = new class($guard, $calls) implements ContainerInterface {
            public function __construct(private readonly CacheBypassGuard $guard, public int &$calls) {}

            public function get(string $id): mixed
            {
                ++$this->calls;

                return $this->guard;
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $services = new DispatcherServices($locator);

        // Same instance, because the locator hands back the same one — not because the facade cached it.
        self::assertSame($guard, $services->cacheBypass());
        self::assertSame($guard, $services->cacheBypass());
        self::assertSame(2, $calls);
    }
}
