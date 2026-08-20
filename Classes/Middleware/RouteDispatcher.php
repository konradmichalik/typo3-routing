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

use KonradMichalik\Typo3Routing\Http\{ConditionalGet, JsonErrorResponse, RequestIdResolver, SiteBasePathResolver};
use KonradMichalik\Typo3Routing\Routing\{PathPrefixGate, RouteRegistry};
use Override;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Symfony\Component\Routing\Exception\{MethodNotAllowedException, ResourceNotFoundException};
use Symfony\Component\Routing\RequestContext;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\{Response, Stream};

use function array_filter;
use function is_string;
use function str_starts_with;

use const ARRAY_FILTER_USE_KEY;

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
     * Where routes actually live (derived from the compiled routes) plus every exclusively claimed path
     * space. A path outside it can never match, so it never reaches the matcher.
     */
    private PathPrefixGate $gate;

    /**
     * The path spaces reserved for this middleware by configuration: inside them an unmatched path is a
     * JSON 404 rather than a page. Empty by default — nothing is claimed, everything else is a page.
     */
    private PathPrefixGate $exclusive;

    /**
     * Only what the gate itself needs is injected directly. Everything used after the gate accepts a
     * request comes from {@see DispatcherServices}, so a page request never builds it — see that class
     * for the measurement that motivated the split.
     */
    public function __construct(
        private RouteRegistry $registry,
        private SiteBasePathResolver $basePathResolver,
        private DispatcherServices $services,
        ExtensionConfiguration $extensionConfiguration,
    ) {
        $configured = '';
        try {
            $value = $extensionConfiguration->get('typo3_routing', 'exclusivePrefixes');
            if (is_string($value)) {
                $configured = $value;
            }
        } catch (Throwable) {
            // Extension not configured yet — then no path space is claimed exclusively.
        }

        $this->exclusive = PathPrefixGate::fromCommaList($configured)
            ->mergedWith(new PathPrefixGate($this->registry->getClassExclusivePrefixes()));
        // A claimed path space must reach the dispatcher even where it holds no route at all, so it is
        // merged into the gate rather than checked separately. Legacy paths are exact literal strings,
        // so they join the case-sensitive prefixes rather than the case-insensitive ones.
        $this->gate = (new PathPrefixGate([...$this->registry->getStaticPrefixes(), ...$this->registry->getLegacyPrefixes()], $this->registry->getCaseInsensitivePrefixes()))->mergedWith($this->exclusive);
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $this->basePathResolver->stripSiteBase($request);

        // 1. Path gate (pure performance filter): a path no route could ever claim → regular page request.
        //    With no routes registered and nothing claimed the gate is empty and rejects everything.
        if (!$this->gate->matches($path)) {
            return $handler->handle($request);
        }

        // CORS preflight is answered up front — before env/auth — so the browser gets its 204 without
        // credentials ever being required. A path that matches no route falls through to the gauntlet.
        $preflight = $this->preflight($request, $path);
        if ($preflight instanceof ResponseInterface) {
            return $preflight;
        }

        $corsConfig = null;
        $routeName = null;
        $response = $this->handleApiRequest($request, $path, $corsConfig, $routeName);
        if (null === $response) {
            // Nothing claims this path exclusively, and it matched no route either — a page, presumably.
            return $handler->handle($request);
        }

        // Every attribute-route response gets a correlation id, the deprecation headers of the route
        // it came from (a no-op unless it opted in), and finally the CORS headers stamped on — using
        // the matched route's own #[Cors] override when it declared one, else the global config.
        $response = RequestIdResolver::decorate($response, $request);
        $response = $this->services->deprecation()->decorate($response, $request, $routeName);
        $response = $this->services->cors()->decorate($response, $request, $corsConfig);

        // Applied once, here, so every response this middleware returns is covered — a matched dispatch,
        // but also the early 404/405/400/401/403/429 responses that never reach dispatch() at all.
        return $this->emptyBodyForHead($request, $response);
    }

    /**
     * Returns null when nothing claims the path: no route matched, and no configured prefix reserves it
     * exclusively for this middleware. The caller then falls through to normal page rendering.
     *
     * @param array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}|null $corsConfig the matched route's own #[Cors] override, set as soon as a route matches; null when no route matched or it declares none
     * @param string|null                                                                                                                  $routeName  the matched route's name, set as soon as a route matches (even if it then errors 4xx); null when no route matched
     */
    private function handleApiRequest(ServerRequestInterface $request, string $path, ?array &$corsConfig, ?string &$routeName): ?ResponseInterface
    {
        // 2. Matching → 404 / 405, or null (path not claimed exclusively, let the page router try).
        $match = $this->matchRoute($request, $path);
        if (null === $match || $match instanceof ResponseInterface) {
            return $match;
        }

        $routeName = (string) ($match['_route'] ?? '');
        $corsConfig = $this->registry->getCorsConfig($routeName);

        // 3. Env filter (match-time, no ExpressionLanguage): an env-bound route is invisible elsewhere.
        if (!$this->services->invoker()->isVisibleInCurrentContext($match['_env'] ?? null)) {
            return JsonErrorResponse::create(404, 'Not Found');
        }

        // 3b. Site/language scope: a route not bound to the current site or language is invisible too.
        //     Checked before either redirect below for the same reason as the env filter above: a
        //     redirect must never reveal a route invisible in the current context.
        if (!$this->services->siteLanguageScope()->isVisibleForSite($match['_sites'] ?? null, $request)
            || !$this->services->siteLanguageScope()->isVisibleForLanguage($match['_languages'] ?? null, $request)) {
            return JsonErrorResponse::create(404, 'Not Found');
        }

        // 3c. Legacy path (opt-in): a request that reached this route through one of its old paths
        //     either redirects (default) or is served directly (`legacyAlias: true`). Checked before the
        //     canonical-variant redirect below: a legacy path's redirect behaviour is governed
        //     exclusively by `legacyAlias`, never by whether the target route separately opted into
        //     `canonical`.
        $redirect = $this->legacyRedirect($match, $request);
        if (null !== $redirect) {
            return $redirect;
        }

        // 3d. Canonical redirect (opt-in): a route matched via a tolerated path variant (trailing slash,
        //     or case) answers 308 to its declared form instead of serving directly, so exactly one URL
        //     survives for caches and search indexing. Checked before the checks below so a client on
        //     the wrong URL is redirected instead of getting a body/requirement error for a request it
        //     is about to resubmit anyway. Never reached for a legacy-path match — its own redirect
        //     decision was already made above.
        $redirect = $this->canonicalRedirect($match, $request);
        if (null !== $redirect) {
            return $redirect;
        }

        // 4. Request body shape (malformed JSON / unsupported content type) → 400/415, on routes that
        //    actually read from the body. Checked before requirements so the cause is named correctly
        //    instead of surfacing as a derived "missing parameter".
        $bodyError = $this->services->invoker()->firstRequestBodyError($match, $request);
        if (null !== $bodyError) {
            return $bodyError;
        }

        // 5. Input requirements (query/body) → 400. Path requirements are matcher-enforced (404).
        $error = $this->services->invoker()->firstInputRequirementError($match, $request);
        if (null !== $error) {
            return JsonErrorResponse::create(400, $error);
        }

        // 6. Rate limiting (opt-in). Enforced before auth so a coarse per-IP limit absorbs token
        //    brute-force attempts before any authentication logic runs. Its headers ride on every
        //    response from here on — success or error — so a client always sees its quota.
        $rateLimit = $this->checkRateLimit($match, $request);
        if (null !== $rateLimit['blocked']) {
            return $this->withHeaders($rateLimit['blocked'], $rateLimit['headers']);
        }

        // 7. Access control (opt-in): authentication (401) then CSRF/request token (403).
        $denied = $this->services->accessGuard()->enforce($match, $request);
        if (null !== $denied) {
            return $this->withHeaders($denied, $rateLimit['headers']);
        }

        // 8. Dispatch (with optional opt-in response cache; disabled for authenticated routes).
        return $this->withHeaders($this->dispatch($match, $request), $rateLimit['headers']);
    }

    /**
     * Null unless this match came through one of the route's `#[Route(legacyPaths:)]` entries and it
     * did not opt into `legacyAlias` (answer directly). Shares the redirect mechanics with
     * canonicalRedirect(), but the decision is governed exclusively by `legacyAlias` — never by whether
     * the target route separately opted into `canonical`.
     *
     * @param array<string, mixed> $match
     */
    private function legacyRedirect(array $match, ServerRequestInterface $request): ?ResponseInterface
    {
        $legacyOf = $match['_legacyOf'] ?? null;
        if (null === $legacyOf) {
            return null;
        }

        $routeName = (string) $legacyOf;
        if ($this->registry->isLegacyAlias($routeName)) {
            return null;
        }

        return $this->buildRedirect($routeName, $match, $request);
    }

    /**
     * Null unless the route opted into `#[Route(canonical: true)]` and this match came from a tolerated
     * variant of its declared path (never for an exact match, never for a 405 — that path already
     * short-circuits in matchRoute() — and never for a legacy-path match, whose own redirect decision is
     * made in legacyRedirect() above).
     *
     * @param array<string, mixed> $match
     */
    private function canonicalRedirect(array $match, ServerRequestInterface $request): ?ResponseInterface
    {
        if (null !== ($match['_legacyOf'] ?? null) || true !== ($match['_canonicalVariant'] ?? false)) {
            return null;
        }

        $routeName = (string) ($match['_route'] ?? '');
        if (!$this->registry->isCanonical($routeName)) {
            return null;
        }

        return $this->buildRedirect($routeName, $match, $request);
    }

    /**
     * The target is regenerated via RouteUrlGenerator rather than reusing the declared path verbatim, so
     * a placeholder route redirects to the concrete path (with the site/language base applied) instead
     * of the literal `{id}` template; the query string carries over unchanged. A fragment is never sent
     * to the server, so there is nothing to preserve there.
     *
     * @param array<string, mixed> $match
     */
    private function buildRedirect(string $routeName, array $match, ServerRequestInterface $request): Response
    {
        /** @var array<string, mixed> $parameters */
        $parameters = array_filter($match, static fn (string $key): bool => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
        $location = $this->services->urlGenerator()->generate($request, $routeName, $parameters);

        $query = $request->getUri()->getQuery();
        if ('' !== $query) {
            $location .= '?'.$query;
        }

        return new Response('php://temp', 308, ['Location' => $location]);
    }

    /**
     * Null means: no route matched, and the path is not inside an exclusively claimed prefix — routes
     * then coexist with ordinary pages, so an unmatched path stays the page router's business.
     *
     * @return array<string, mixed>|ResponseInterface|null the matched route attributes, or a 404/405 error response
     */
    private function matchRoute(ServerRequestInterface $request, string $path): array|ResponseInterface|null
    {
        try {
            return $this->services->matcher()->match($path, $this->requestContext($request));
        } catch (ResourceNotFoundException) {
            return $this->exclusive->matches($path) ? JsonErrorResponse::create(404, 'Not Found') : null;
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
        return $this->services->corsPreflight()->resolve($request, $path, $this->requestContext($request));
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

        return $this->services->rateLimitCheck()->evaluate($routeName, $config, $this->services->clientKeyResolver()->resolve($config, $request));
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
            $cached = $this->services->cache()->withCacheStatus($cached, $cacheConfig, $request, 'HIT');

            return ConditionalGet::notModified($request, $cached) ?? $cached;
        }

        $response = $this->services->invoker()->invoke($match, $request);
        // The real body is stored (and returned) here; process() empties it for HEAD afterwards — a HEAD
        // request must prime the same cache entry a subsequent GET reads, not an empty one.
        $response = $this->writeCache($cacheConfig, $routeName, $request, $response);
        $response = $this->services->cache()->withCacheStatus($response, $cacheConfig, $request, 'MISS');

        // notModified is a no-op unless the response was cached (only then does it carry an ETag).
        return ConditionalGet::notModified($request, $response) ?? $response;
    }

    /**
     * HEAD matches the same route a GET would (Symfony canonicalises it during matching), so it must
     * carry the headers a GET response would, with the body dropped — not a body TYPO3 discards later.
     * Neither a GET nor a HEAD response here carries Content-Length, so the two stay consistent.
     * Called once, centrally, in process() — so every response path this middleware returns is covered,
     * not only a matched dispatch.
     */
    private function emptyBodyForHead(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ('HEAD' !== $request->getMethod()) {
            return $response;
        }

        return $response->withBody(new Stream('php://temp', 'rw'));
    }

    /**
     * Serves a cached response for a cacheable GET, or null (miss, non-GET, or caching not opted in).
     *
     * @param array{lifetime: int, tags: list<string>, ignoreParams: list<string>}|null $cacheConfig
     */
    private function readCache(?array $cacheConfig, string $routeName, ServerRequestInterface $request): ?ResponseInterface
    {
        if (null === $cacheConfig || 'GET' !== $this->services->cache()->cacheableMethod($request) || $this->services->cacheBypass()->skipsRead($request)) {
            return null;
        }

        return $this->services->cache()->get($this->services->cache()->buildKey($routeName, $request, $cacheConfig['ignoreParams']));
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
        if (null === $cacheConfig || 'GET' !== $this->services->cache()->cacheableMethod($request) || 200 !== $response->getStatusCode() || $this->services->cacheBypass()->skipsWrite($request)) {
            return $response;
        }

        // Attach the ETag before storing so this first response and later cache hits share the validator.
        $response = $this->services->cache()->withETag($response);
        $this->services->cache()->store($this->services->cache()->buildKey($routeName, $request, $cacheConfig['ignoreParams']), $response, $cacheConfig['lifetime'], $cacheConfig['tags']);

        return $response;
    }
}
