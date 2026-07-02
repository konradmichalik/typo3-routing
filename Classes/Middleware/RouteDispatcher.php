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
use KonradMichalik\Typo3Routing\Cache\ResponseCacheManager;
use KonradMichalik\Typo3Routing\Http\{ConditionalGet, CorsHandler, JsonErrorResponse, RequestBody, SiteBasePathResolver};
use KonradMichalik\Typo3Routing\RateLimit\RateLimitEnforcer;
use KonradMichalik\Typo3Routing\Routing\{ArgumentResolutionException, ControllerArgumentResolver, RouteRegistry};
use Override;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Symfony\Component\Routing\Exception\{MethodNotAllowedException, ResourceNotFoundException};
use Symfony\Component\Routing\RequestContext;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\NormalizedParams;

use function array_key_exists;
use function array_values;
use function assert;
use function is_array;
use function is_object;
use function is_string;
use function max;
use function sprintf;
use function time;

/**
 * RouteDispatcher.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class RouteDispatcher implements MiddlewareInterface
{
    private string $prefix;

    public function __construct(
        private RouteRegistry $registry,
        private SiteBasePathResolver $basePathResolver,
        private ResponseCacheManager $cache,
        private RateLimitEnforcer $rateLimiter,
        private ControllerArgumentResolver $argumentResolver,
        private AccessGuard $accessGuard,
        private CorsHandler $cors,
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
        $this->prefix = $prefix;
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $this->basePathResolver->stripSiteBase($request);

        // 1. Prefix gate (pure performance filter): outside the prefix → regular page request.
        if ('' !== $this->prefix && !str_starts_with($path, $this->prefix)) {
            return $handler->handle($request);
        }

        // CORS preflight is answered up front — before env/auth — so the browser gets its 204 without
        // credentials ever being required. A path that matches no route falls through to the gauntlet.
        $preflight = $this->preflight($request, $path);
        if ($preflight instanceof ResponseInterface) {
            return $preflight;
        }

        // Every attribute-route response (success or error) gets the CORS headers stamped on.
        return $this->cors->decorate($this->handleApiRequest($request, $path), $request);
    }

    private function handleApiRequest(ServerRequestInterface $request, string $path): ResponseInterface
    {
        // 2. Matching → 404 / 405.
        $match = $this->matchRoute($request, $path);
        if ($match instanceof ResponseInterface) {
            return $match;
        }

        // 3. Env filter (match-time, no ExpressionLanguage): an env-bound route is invisible elsewhere.
        $env = $match['_env'] ?? null;
        if (is_string($env) && '' !== $env && !$this->matchesCurrentContext($env)) {
            return JsonErrorResponse::create(404, 'Not Found');
        }

        // 4. Input requirements (query/body) → 400. Path requirements are matcher-enforced (404).
        $error = $this->firstInputRequirementError($match, $request);
        if (null !== $error) {
            return JsonErrorResponse::create(400, $error);
        }

        // 5. Rate limiting (opt-in). Enforced before auth so a coarse per-IP limit absorbs token
        //    brute-force attempts before any authentication logic runs.
        $rateLimited = $this->enforceRateLimit($match, $request);
        if (null !== $rateLimited) {
            return $rateLimited;
        }

        // 6. Access control (opt-in): authentication (401) then CSRF/request token (403).
        $denied = $this->accessGuard->enforce($match, $request);
        if (null !== $denied) {
            return $denied;
        }

        // 7. Dispatch (with optional opt-in response cache; disabled for authenticated routes).
        return $this->dispatch($match, $request);
    }

    /**
     * @return array<string, mixed>|ResponseInterface the matched route attributes, or a 404/405 error response
     */
    private function matchRoute(ServerRequestInterface $request, string $path): array|ResponseInterface
    {
        try {
            return $this->registry->getMatcher($this->requestContext($request))->match($path);
        } catch (ResourceNotFoundException) {
            return JsonErrorResponse::create(404, 'Not Found');
        } catch (MethodNotAllowedException $exception) {
            return JsonErrorResponse::create(405, 'Method Not Allowed', [
                'Allow' => implode(', ', $exception->getAllowedMethods()),
            ]);
        }
    }

    /**
     * Answers a CORS preflight for a path that matches at least one route. Returns null when CORS is
     * off, the request is not a preflight, or the path matches nothing (so it continues the gauntlet).
     */
    private function preflight(ServerRequestInterface $request, string $path): ?ResponseInterface
    {
        if (!$this->cors->isEnabled() || 'OPTIONS' !== $request->getMethod() || '' === $request->getHeaderLine('Access-Control-Request-Method')) {
            return null;
        }

        try {
            // OPTIONS is rarely a declared method, so the matcher usually reports the allowed methods
            // for the path via MethodNotAllowedException — exactly what the preflight needs.
            $match = $this->registry->getMatcher($this->requestContext($request))->match($path);
        } catch (MethodNotAllowedException $exception) {
            return $this->cors->preflightResponse(array_values($exception->getAllowedMethods()), $request);
        } catch (ResourceNotFoundException) {
            return null;
        }

        $routeName = (string) ($match['_route'] ?? '');
        $methods = $this->registry->getRoutes()[$routeName]['methods'] ?? [];

        return $this->cors->preflightResponse($methods, $request);
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
     */
    private function enforceRateLimit(array $match, ServerRequestInterface $request): ?ResponseInterface
    {
        $routeName = (string) ($match['_route'] ?? '');
        $config = $this->registry->getRateLimit($routeName);
        if (null === $config) {
            return null;
        }

        $result = $this->rateLimiter->consume($routeName, $config, $this->clientId($request));
        if ($result->isAccepted()) {
            return null;
        }

        return JsonErrorResponse::create(429, 'Too Many Requests', [
            'Retry-After' => (string) max(0, $result->getRetryAfter()->getTimestamp() - time()),
        ]);
    }

    private function clientId(ServerRequestInterface $request): string
    {
        // normalizedParams is set early in the frontend stack and resolves reverse-proxy headers.
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            return $normalizedParams->getRemoteAddress();
        }

        $remoteAddress = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return is_string($remoteAddress) ? $remoteAddress : '';
    }

    /**
     * Validates `requirements` whose name is not a matched path placeholder against the query and parsed
     * body: a missing parameter or a value violating the regex yields a 400.
     *
     * @param array<string, mixed> $match
     */
    private function firstInputRequirementError(array $match, ServerRequestInterface $request): ?string
    {
        $requirements = $match['_requirements'] ?? null;
        $inputs = array_merge($request->getQueryParams(), RequestBody::toArray($request));

        foreach (is_array($requirements) ? $requirements : [] as $name => $pattern) {
            $key = (string) $name;
            // A matched path placeholder is already validated by the matcher.
            if (array_key_exists($key, $match)) {
                continue;
            }
            if (!array_key_exists($key, $inputs)) {
                return sprintf('Missing required parameter: %s', $key);
            }
            if (is_string($pattern) && $this->inputViolatesPattern($pattern, $inputs[$key])) {
                return sprintf('Invalid value for parameter: %s', $key);
            }
        }

        return null;
    }

    /**
     * A non-empty pattern is violated when the value is not a string or does not fully match the regex.
     */
    private function inputViolatesPattern(string $pattern, mixed $value): bool
    {
        return '' !== $pattern && (!is_string($value) || 1 !== preg_match('#^(?:'.$pattern.')$#', $value));
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
            return ConditionalGet::notModified($request, $cached) ?? $cached;
        }

        $response = $this->invokeController($match, $request);
        $response = $this->writeCache($cacheConfig, $routeName, $request, $response);

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
        if (null === $cacheConfig || 'GET' !== $request->getMethod()) {
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
        if (null === $cacheConfig || 'GET' !== $request->getMethod() || 200 !== $response->getStatusCode()) {
            return $response;
        }

        // Attach the ETag before storing so this first response and later cache hits share the validator.
        $response = $this->cache->withETag($response);
        $this->cache->store($this->cache->buildKey($routeName, $request, $cacheConfig['ignoreParams']), $response, $cacheConfig['lifetime'], $cacheConfig['tags']);

        return $response;
    }

    /**
     * @param array<string, mixed> $match
     */
    private function invokeController(array $match, ServerRequestInterface $request): ResponseInterface
    {
        [$serviceId, $method] = explode('::', (string) $match['_controller'], 2);
        $controller = $this->registry->getControllerLocator()->get($serviceId);
        assert(is_object($controller));

        // Path placeholders stay available as request attributes for controllers that take the request.
        foreach ($match as $key => $value) {
            if (!str_starts_with($key, '_')) {
                $request = $request->withAttribute($key, $value);
            }
        }

        $routeName = (string) ($match['_route'] ?? '');
        try {
            $arguments = $this->argumentResolver->resolve($this->registry->getArguments($routeName), $match, $request);
        } catch (ArgumentResolutionException $exception) {
            return JsonErrorResponse::create(400, $exception->getMessage());
        }

        /** @var callable(mixed...): ResponseInterface $target */
        $target = [$controller, $method];

        return $target(...$arguments);
    }

    private function matchesCurrentContext(string $env): bool
    {
        $current = explode('/', (string) Environment::getContext())[0];

        return strtolower($current) === strtolower($env);
    }
}
