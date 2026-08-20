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

namespace KonradMichalik\Typo3Routing\Middleware;

use KonradMichalik\Typo3Routing\Authentication\AccessGuard;
use KonradMichalik\Typo3Routing\Cache\{CacheBypassGuard, ResponseCacheManager};
use KonradMichalik\Typo3Routing\Http\{CorsHandler, CorsPreflightResolver, DeprecationHeaders, RouteUrlGenerator};
use KonradMichalik\Typo3Routing\RateLimit\{ClientKeyResolver, RateLimitCheck};
use KonradMichalik\Typo3Routing\Routing\{ControllerInvoker, RouteMatcher, SiteLanguageScope};
use Psr\Container\ContainerInterface;

use function assert;

/**
 * DispatcherServices.
 *
 * Everything {@see RouteDispatcher} needs *after* its path gate has accepted a request, reached through
 * a service locator instead of its constructor, so that none of it is built for a request that never
 * gets that far.
 *
 * This exists for a measured reason rather than a stylistic one. The dispatcher is constructed on every
 * frontend request, ordinary page requests included, and its dependency graph pulls in around twenty
 * classes. At roughly 25-30 µs to load and link each, that cost dominated the extension's entire
 * overhead: ~0.57 ms of the ~0.70 ms a matched request spent, and the same ~0.57 ms on every page
 * request, which the gate rejects in well under a microsecond but only once the graph behind it exists.
 * Deferring these twelve services took the cost of obtaining the dispatcher to ~0.18 ms. See
 * docs/background/performance.md.
 *
 * A locator rather than `#[Lazy]` because lazy ghosts must subclass their target, which `final` forbids
 * on PHP 8.2 (native lazy objects, which can do it, need PHP 8.4). A typed accessor per service rather
 * than raw `get()` calls at the call sites so the dispatcher keeps reading like it did, and so PHPStan
 * still knows what everything is.
 *
 * Nothing is memoised here. Every accessor delegates, and instance identity is whatever the container
 * provides — the same instance each time for the shared definitions these all are. Caching in the facade
 * as well would be a second source of truth about identity for no gain, since a `get()` of an
 * already-built service measures around a microsecond.
 *
 * @internal dispatch plumbing, not part of the metadata surface — see docs/background/extending.md
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class DispatcherServices
{
    public function __construct(private ContainerInterface $services) {}

    public function matcher(): RouteMatcher
    {
        return $this->service(RouteMatcher::class);
    }

    public function cache(): ResponseCacheManager
    {
        return $this->service(ResponseCacheManager::class);
    }

    public function rateLimitCheck(): RateLimitCheck
    {
        return $this->service(RateLimitCheck::class);
    }

    public function invoker(): ControllerInvoker
    {
        return $this->service(ControllerInvoker::class);
    }

    public function accessGuard(): AccessGuard
    {
        return $this->service(AccessGuard::class);
    }

    public function cors(): CorsHandler
    {
        return $this->service(CorsHandler::class);
    }

    public function corsPreflight(): CorsPreflightResolver
    {
        return $this->service(CorsPreflightResolver::class);
    }

    public function cacheBypass(): CacheBypassGuard
    {
        return $this->service(CacheBypassGuard::class);
    }

    public function clientKeyResolver(): ClientKeyResolver
    {
        return $this->service(ClientKeyResolver::class);
    }

    public function urlGenerator(): RouteUrlGenerator
    {
        return $this->service(RouteUrlGenerator::class);
    }

    public function siteLanguageScope(): SiteLanguageScope
    {
        return $this->service(SiteLanguageScope::class);
    }

    public function deprecation(): DeprecationHeaders
    {
        return $this->service(DeprecationHeaders::class);
    }

    /**
     * The locator hands back `mixed`, so the class is asserted rather than trusted: a locator wired to
     * the wrong service is a container-configuration mistake, and it should fail here by name instead
     * of surfacing as a "call to undefined method" further down the dispatch chain.
     *
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = $this->services->get($id);
        assert($service instanceof $id);

        return $service;
    }
}
