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

use KonradMichalik\Typo3Routing\Cache\ResponseCacheManager;
use KonradMichalik\Typo3Routing\Http\SiteBasePathResolver;
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use Override;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Symfony\Component\Routing\Exception\{MethodNotAllowedException, ResourceNotFoundException};
use Symfony\Component\Routing\RequestContext;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\Response;

use function array_key_exists;
use function assert;
use function is_array;
use function is_object;
use function is_string;
use function sprintf;

/**
 * RouteDispatcher.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RouteDispatcher implements MiddlewareInterface
{
    private string $prefix;

    public function __construct(
        private RouteRegistry $registry,
        private SiteBasePathResolver $basePathResolver,
        private ResponseCacheManager $cache,
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

        $context = new RequestContext();
        $context->setMethod($request->getMethod());
        $context->setHost($request->getUri()->getHost());
        $context->setScheme($request->getUri()->getScheme());

        // 2. Matching.
        try {
            $match = $this->registry->getMatcher($context)->match($path);
        } catch (ResourceNotFoundException) {
            return $this->errorResponse(404, 'Not Found');
        } catch (MethodNotAllowedException $exception) {
            return $this->errorResponse(405, 'Method Not Allowed', [
                'Allow' => implode(', ', $exception->getAllowedMethods()),
            ]);
        }

        // 3. Env filter (match-time, no ExpressionLanguage): an env-bound route is invisible elsewhere.
        $env = $match['_env'] ?? null;
        if (is_string($env) && '' !== $env && !$this->matchesCurrentContext($env)) {
            return $this->errorResponse(404, 'Not Found');
        }

        // 4. Input requirements (query/body) → 400. Path requirements are matcher-enforced (404).
        $error = $this->firstInputRequirementError($match, $request);
        if (null !== $error) {
            return $this->errorResponse(400, $error);
        }

        // 5. Dispatch (with optional opt-in response cache).
        return $this->dispatch($match, $request);
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
        $body = $request->getParsedBody();
        $inputs = array_merge($request->getQueryParams(), is_array($body) ? $body : []);

        foreach (is_array($requirements) ? $requirements : [] as $name => $pattern) {
            $key = (string) $name;
            // A matched path placeholder is already validated by the matcher.
            if (array_key_exists($key, $match)) {
                continue;
            }
            if (!array_key_exists($key, $inputs)) {
                return sprintf('Missing required parameter: %s', $key);
            }
            if (is_string($pattern) && '' !== $pattern && (!is_string($inputs[$key]) || 1 !== preg_match('#^(?:'.$pattern.')$#', $inputs[$key]))) {
                return sprintf('Invalid value for parameter: %s', $key);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $match
     */
    private function dispatch(array $match, ServerRequestInterface $request): ResponseInterface
    {
        $routeName = (string) ($match['_route'] ?? '');
        $cacheConfig = $this->registry->getCacheConfig($routeName);
        $cacheKey = null;

        // Only safe GET requests are cached; the success response format stays the controller's choice.
        if (null !== $cacheConfig && 'GET' === $request->getMethod()) {
            $cacheKey = $this->cache->buildKey($routeName, $request, $cacheConfig['ignoreParams']);
            $cached = $this->cache->get($cacheKey);
            if ($cached instanceof ResponseInterface) {
                return $cached;
            }
        }

        $response = $this->invokeController($match, $request);

        if (null !== $cacheKey && null !== $cacheConfig && 200 === $response->getStatusCode()) {
            $this->cache->store($cacheKey, $response, $cacheConfig['lifetime'], $cacheConfig['tags']);
        }

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

        foreach ($match as $key => $value) {
            if (!str_starts_with($key, '_')) {
                $request = $request->withAttribute($key, $value);
            }
        }

        /** @var callable(ServerRequestInterface): ResponseInterface $target */
        $target = [$controller, $method];

        return $target($request);
    }

    private function matchesCurrentContext(string $env): bool
    {
        $current = explode('/', (string) Environment::getContext())[0];

        return strtolower($current) === strtolower($env);
    }

    /**
     * @param array<string, string> $headers
     */
    private function errorResponse(int $status, string $message, array $headers = []): Response
    {
        // JSON is intentional on the dispatch level; the success response format is the controller's call.
        $response = new Response('php://temp', $status, array_merge(['Content-Type' => 'application/json'], $headers));
        $response->getBody()->write(json_encode(['error' => $message, 'status' => $status], \JSON_THROW_ON_ERROR));

        return $response;
    }
}
