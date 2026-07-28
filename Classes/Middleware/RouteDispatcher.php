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
use KonradMichalik\Typo3Routing\Http\{ConditionalGet, CorsHandler, CorsPreflightResolver, JsonErrorResponse, RequestIdResolver, SiteBasePathResolver};
use KonradMichalik\Typo3Routing\RateLimit\{ClientKeyResolver, RateLimitCheck};
use KonradMichalik\Typo3Routing\Routing\{ControllerInvoker, RouteRegistry};
use Override;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Symfony\Component\Routing\Exception\{MethodNotAllowedException, ResourceNotFoundException};
use Symfony\Component\Routing\RequestContext;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function is_string;
use function trim;

/**
 * RouteDispatcher.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class RouteDispatcher implements MiddlewareInterface
{
    /**
     * @var list<string>
     */
    private array $prefixes;

    public function __construct(
        private RouteRegistry $registry,
        private SiteBasePathResolver $basePathResolver,
        private ResponseCacheManager $cache,
        private RateLimitCheck $rateLimitCheck,
        private ControllerInvoker $invoker,
        private AccessGuard $accessGuard,
        private CorsHandler $cors,
        private CorsPreflightResolver $corsPreflight,
        private CacheBypassGuard $cacheBypass,
        private ClientKeyResolver $clientKeyResolver,
        ExtensionConfiguration $extensionConfiguration,
    ) {
        $prefix = '/api/';
        try {
            $configured = $extensionConfiguration->get('typo3_routing', 'prefix');
            if (is_string($configured)) {
                $prefix = $configured;
            }
        } catch (Throwable) {
            // Extension not configured yet — fall back to the default prefix.
        }
        // Comma-separated list, mirroring CorsHandler::$allowedOrigins parsing.
        $this->prefixes = array_values(array_filter(array_map(trim(...), explode(',', $prefix)), static fn (string $item): bool => '' !== $item));
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $this->basePathResolver->stripSiteBase($request);

        // 1. Prefix gate (pure performance filter): outside every configured prefix → regular page request.
        if ([] !== $this->prefixes && !$this->matchesAnyPrefix($path)) {
            return $handler->handle($request);
        }

        // CORS preflight is answered up front — before env/auth — so the browser gets its 204 without
        // credentials ever being required. A path that matches no route falls through to the gauntlet.
        $preflight = $this->preflight($request, $path);
        if ($preflight instanceof ResponseInterface) {
            return $preflight;
        }

        $corsConfig = null;
        $response = $this->handleApiRequest($request, $path, $corsConfig);
        if (null === $response) {
            // No prefix claims this path exclusively, and it matched no route either — a page, presumably.
            return $handler->handle($request);
        }

        // Every attribute-route response gets a correlation id and, finally, the CORS headers stamped on
        // — using the matched route's own #[Cors] override when it declared one, else the global config.
        $response = RequestIdResolver::decorate($response, $request);

        return $this->cors->decorate($response, $request, $corsConfig);
    }

    private function matchesAnyPrefix(string $path): bool
    {
        return [] !== array_filter($this->prefixes, static fn (string $prefix): bool => str_starts_with($path, $prefix));
    }

    /**
     * Returns null when nothing claims the path: no route matched, and no prefix reserves it exclusively
     * for this middleware. The caller then falls through to normal page rendering.
     *
     * @param array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}|null $corsConfig the matched route's own #[Cors] override, set as soon as a route matches; null when no route matched or it declares none
     */
    private function handleApiRequest(ServerRequestInterface $request, string $path, ?array &$corsConfig): ?ResponseInterface
    {
        // 2. Matching → 404 / 405, or null (unprefixed mode, let the page router try).
        $match = $this->matchRoute($request, $path);
        if (null === $match || $match instanceof ResponseInterface) {
            return $match;
        }

        $corsConfig = $this->registry->getCorsConfig((string) ($match['_route'] ?? ''));

        // 3. Env filter (match-time, no ExpressionLanguage): an env-bound route is invisible elsewhere.
        if (!$this->invoker->isVisibleInCurrentContext($match['_env'] ?? null)) {
            return JsonErrorResponse::create(404, 'Not Found');
        }

        // 4. Input requirements (query/body) → 400. Path requirements are matcher-enforced (404).
        $error = $this->invoker->firstInputRequirementError($match, $request);
        if (null !== $error) {
            return JsonErrorResponse::create(400, $error);
        }

        // 5. Rate limiting (opt-in). Enforced before auth so a coarse per-IP limit absorbs token
        //    brute-force attempts before any authentication logic runs. Its headers ride on every
        //    response from here on — success or error — so a client always sees its quota.
        $rateLimit = $this->checkRateLimit($match, $request);
        if (null !== $rateLimit['blocked']) {
            return $this->withHeaders($rateLimit['blocked'], $rateLimit['headers']);
        }

        // 6. Access control (opt-in): authentication (401) then CSRF/request token (403).
        $denied = $this->accessGuard->enforce($match, $request);
        if (null !== $denied) {
            return $this->withHeaders($denied, $rateLimit['headers']);
        }

        // 7. Dispatch (with optional opt-in response cache; disabled for authenticated routes).
        return $this->withHeaders($this->dispatch($match, $request), $rateLimit['headers']);
    }

    /**
     * Null means: no route matched, and no prefix is configured — routes then declare their full path
     * individually per controller and must coexist with ordinary pages everywhere else.
     *
     * @return array<string, mixed>|ResponseInterface|null the matched route attributes, or a 404/405 error response
     */
    private function matchRoute(ServerRequestInterface $request, string $path): array|ResponseInterface|null
    {
        try {
            return $this->registry->getMatcher($this->requestContext($request))->match($path);
        } catch (ResourceNotFoundException) {
            return [] === $this->prefixes ? null : JsonErrorResponse::create(404, 'Not Found');
        } catch (MethodNotAllowedException $exception) {
            return JsonErrorResponse::create(405, 'Method Not Allowed', [
                'Allow' => implode(', ', $exception->getAllowedMethods()),
            ]);
        }
    }

    /**
     * Answers a CORS preflight for a path that matches at least one route. Returns null when CORS is
     * off (globally and for the matched route), the request is not a preflight, or the path matches
     * nothing (so it continues the gauntlet).
     */
    private function preflight(ServerRequestInterface $request, string $path): ?ResponseInterface
    {
        return $this->corsPreflight->resolve($request, $path, $this->requestContext($request));
    }

    private function requestContext(ServerRequestInterface $request): RequestContext
    {
        $context = new RequestContext();
        $context->setMethod($request->getMethod());
        $context->setHost($request->getUri()->getHost());
        $context->setScheme($request->getUri()->getScheme());

        return $context;
    }

    /**
     * @param array<string, mixed> $match
     *
     * @return array{blocked: ResponseInterface|null, headers: array<string, string>}
     */
    private function checkRateLimit(array $match, ServerRequestInterface $request): array
    {
        $routeName = (string) ($match['_route'] ?? '');
        $config = $this->registry->getRateLimit($routeName);
        if (null === $config) {
            return ['blocked' => null, 'headers' => []];
        }

        return $this->rateLimitCheck->evaluate($routeName, $config, $this->clientKeyResolver->resolve($config, $request));
    }

    /**
     * @param array<string, string> $headers
     */
    private function withHeaders(ResponseInterface $response, array $headers): ResponseInterface
    {
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $match
     */
    private function dispatch(array $match, ServerRequestInterface $request): ResponseInterface
    {
        $routeName = (string) ($match['_route'] ?? '');

        // Authenticated routes are never cached: the cache key does not vary by identity, so a shared
        // entry would leak one client's response to another. Force no-store regardless of #[Cache].
        $cacheConfig = [] === $this->registry->getAuthenticators($routeName)
            ? $this->registry->getCacheConfig($routeName)
            : null;

        $cached = $this->readCache($cacheConfig, $routeName, $request);
        if ($cached instanceof ResponseInterface) {
            // A cached entry already carries its ETag, so a conditional GET can short-circuit.
            $cached = $this->cache->withCacheStatus($cached, $cacheConfig, $request, 'HIT');

            return ConditionalGet::notModified($request, $cached) ?? $cached;
        }

        $response = $this->invoker->invoke($match, $request);
        $response = $this->writeCache($cacheConfig, $routeName, $request, $response);
        $response = $this->cache->withCacheStatus($response, $cacheConfig, $request, 'MISS');

        // notModified is a no-op unless the response was cached (only then does it carry an ETag).
        return ConditionalGet::notModified($request, $response) ?? $response;
    }

    /**
     * Serves a cached response for a cacheable GET, or null (miss, non-GET, or caching not opted in).
     *
     * @param array{lifetime: int, tags: list<string>, ignoreParams: list<string>}|null $cacheConfig
     */
    private function readCache(?array $cacheConfig, string $routeName, ServerRequestInterface $request): ?ResponseInterface
    {
        if (null === $cacheConfig || 'GET' !== $request->getMethod() || $this->cacheBypass->skipsRead($request)) {
            return null;
        }

        return $this->cache->get($this->cache->buildKey($routeName, $request, $cacheConfig['ignoreParams']));
    }

    /**
     * Stores a successful GET response when caching is opted in; the success format stays the controller's.
     * Returns the response to send — ETag-tagged when cached (so the first response and later cache hits
     * share the validator), otherwise the response unchanged.
     *
     * @param array{lifetime: int, tags: list<string>, ignoreParams: list<string>}|null $cacheConfig
     */
    private function writeCache(?array $cacheConfig, string $routeName, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (null === $cacheConfig || 'GET' !== $request->getMethod() || 200 !== $response->getStatusCode() || $this->cacheBypass->skipsWrite($request)) {
            return $response;
        }

        // Attach the ETag before storing so this first response and later cache hits share the validator.
        $response = $this->cache->withETag($response);
        $this->cache->store($this->cache->buildKey($routeName, $request, $cacheConfig['ignoreParams']), $response, $cacheConfig['lifetime'], $cacheConfig['tags']);

        return $response;
    }
}
