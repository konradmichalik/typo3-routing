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

namespace KonradMichalik\Typo3Routing\Routing;

use InvalidArgumentException;
use JsonException;
use KonradMichalik\Typo3Routing\Authentication\AccessGuard;
use KonradMichalik\Typo3Routing\Http\{JsonErrorResponse, RouteUrlGenerator};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface, UriInterface};
use Symfony\Component\Routing\Exception\{InvalidParameterException, MissingMandatoryParametersException};
use TYPO3\CMS\Core\Http\{Stream, Uri};

use function array_key_exists;
use function http_build_query;
use function in_array;
use function is_bool;
use function is_float;
use function is_int;
use function json_encode;
use function preg_match_all;
use function sprintf;

/**
 * RouteInvoker.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RouteInvoker
{
    public function __construct(
        private RouteRegistry $registry,
        private ControllerInvoker $invoker,
        private AccessGuard $accessGuard,
        private RouteUrlGenerator $urlGenerator,
    ) {}

    /**
     * Calls a route's controller for a set of input values, without an inbound HTTP request against
     * the route's own path — the sanctioned seam for tooling that exposes routes over another
     * transport. Replicated are the steps belonging to the route's own contract (env filter, path and
     * input requirements, authentication); skipped are those belonging to the HTTP transport (CORS,
     * rate limiting, response caching, and the CSRF request token — no browser is involved).
     *
     * Authentication is checked against the credentials the calling request carries. Rate limiting
     * being absent means a consumer re-exposing these routes owns abuse control on its own transport.
     * See docs/EXTENDING.md for the full contract and its BC promise.
     *
     * @param array<string, mixed> $input values keyed by the argument's wire name (see RouteRegistry::getArguments())
     *
     * @return ResponseInterface the route's own response, or a problem response for input the route rejects
     *
     * @throws InvalidArgumentException when no route of that name is registered
     */
    public function invoke(string $routeName, array $input, ServerRequestInterface $request): ResponseInterface
    {
        $route = $this->registry->getRoutes()[$routeName]
            ?? throw new InvalidArgumentException(sprintf('No route named "%s" is registered.', $routeName), 1750000026);

        // 1. Env filter: an env-bound route is invisible outside its application context, here as much
        //    as over HTTP — a consumer must not reach a route a browser could not.
        if (!$this->invoker->isVisibleInCurrentContext($route['env'])) {
            return JsonErrorResponse::create(404, 'Not Found');
        }

        $placeholders = $this->placeholders($route['path']);
        $pathValues = $this->pathValues($input, $placeholders);

        // 2. Path values: generating the route's own URL is what validates them. A placeholder without
        //    a value or one violating its requirement cannot produce a URL — and over HTTP it could
        //    never have matched either, so both are the matcher's 404 rather than a controller call.
        try {
            $path = $this->urlGenerator->generate($request, $routeName, $pathValues);
        } catch (MissingMandatoryParametersException|InvalidParameterException) {
            return JsonErrorResponse::create(404, 'Not Found');
        }

        // Mirrors what the matcher hands the dispatcher: the internal keys first so no route default
        // can overwrite them, then the resolved placeholders, then the route's own defaults.
        $match = [
            '_route' => $routeName,
            '_controller' => $route['controller'],
            '_requirements' => $route['requirements'],
        ] + $pathValues + ($route['defaults'] ?? []);

        // A value that cannot be carried in a JSON body is bad input, so it answers like any other —
        // over HTTP such a payload would not have decoded either.
        try {
            $synthetic = $this->syntheticRequest($request, $route, $path, $input, $placeholders, $this->registry->getArguments($routeName));
        } catch (JsonException) {
            return JsonErrorResponse::create(400, 'Invalid body input');
        }

        // 3. Input requirements (query/body) → 400, exactly as for an inbound request.
        $error = $this->invoker->firstInputRequirementError($match, $synthetic);
        if (null !== $error) {
            return JsonErrorResponse::create(400, $error);
        }

        // 4. Authentication (401) against the credentials the calling request carries. The request-token
        //    check is deliberately skipped: CSRF protects browser-initiated state changes, and this
        //    invocation has no browser — see docs/EXTENDING.md.
        $denied = $this->accessGuard->enforceAuthentication($match, $synthetic);
        if (null !== $denied) {
            return $denied;
        }

        // 5. Dispatch. Rate limiting and the response cache stay out: both are HTTP-transport
        //    mechanisms, not part of the route's correctness contract.
        return $this->invoker->invoke($match, $synthetic);
    }

    /**
     * The route path's placeholder names, in the same shape the compile-time argument specs use.
     *
     * @return list<string>
     */
    private function placeholders(string $path): array
    {
        preg_match_all('/\{(\w+)\}/', $path, $matches);

        return $matches[1];
    }

    /**
     * The supplied values for the path's placeholders, stringified: over HTTP a placeholder is always
     * a string, and requirement patterns are matched against strings.
     *
     * @param array<string, mixed> $input
     * @param list<string>         $placeholders
     *
     * @return array<string, mixed>
     */
    private function pathValues(array $input, array $placeholders): array
    {
        $values = [];
        foreach ($placeholders as $name) {
            if (array_key_exists($name, $input)) {
                $values[$name] = $this->asTransportValue($input[$name]);
            }
        }

        return $values;
    }

    /**
     * Builds the request the controller sees: the calling request's context (site, language, user,
     * credentials) with this route's method, path and inputs put where the argument resolver looks
     * for them. The body stream is always a fresh one — reusing the caller's would hand its payload
     * to a body-sourced argument that this invocation left empty.
     *
     * @param array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, priority?: int, defaults?: array<string, mixed>, schemes?: list<string>, host?: string|null, description?: string|null, caseInsensitive?: bool} $route
     * @param array<string, mixed>                                                                                                                                                                                                                                                  $input
     * @param list<string>                                                                                                                                                                                                                                                          $placeholders
     * @param list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>                                                                                                                                                        $specs
     */
    private function syntheticRequest(ServerRequestInterface $request, array $route, string $path, array $input, array $placeholders, array $specs): ServerRequestInterface
    {
        $query = [];
        $body = [];
        foreach ($input as $name => $value) {
            $source = $this->sourceOf($specs, $name);
            if ('body' === $source) {
                // A JSON body carries native types, so these values are passed through untouched.
                $body[$name] = $value;

                continue;
            }
            // A placeholder value travels in the path (and reaches the resolver through the match).
            if (in_array($name, $placeholders, true) && (null === $source || 'path' === $source)) {
                continue;
            }
            $query[$name] = $this->asTransportValue($value);
        }

        $synthetic = $request
            ->withMethod($route['methods'][0] ?? 'GET')
            ->withUri($this->uri($request, $path, $query))
            ->withQueryParams($query)
            ->withBody(new Stream('php://temp', 'rw'))
            ->withoutHeader('Content-Type')
            ->withoutHeader('Content-Length');

        if ([] === $body) {
            return $synthetic->withParsedBody(null);
        }

        // Written to the stream as well, so a controller decoding the raw body itself sees the same
        // payload as one taking typed body arguments.
        $synthetic->getBody()->write(json_encode($body, \JSON_THROW_ON_ERROR));

        return $synthetic
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($body);
    }

    /**
     * The declared source of the argument that reads this input key, or null when no argument claims it.
     *
     * @param list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}> $specs
     */
    private function sourceOf(array $specs, string $name): ?string
    {
        foreach ($specs as $spec) {
            if ($spec['name'] === $name) {
                return $spec['source'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function uri(ServerRequestInterface $request, string $path, array $query): UriInterface
    {
        // A route constrained to another scheme or host makes the generator return an absolute URL,
        // which already carries its own host — the calling request's would then be the wrong one.
        $generated = new Uri($path);
        $uri = '' === $generated->getHost() ? $request->getUri()->withPath($path) : $generated;

        return $uri->withQuery(http_build_query($query));
    }

    /**
     * Scalars become strings, as they would be crossing HTTP as a path segment or query parameter;
     * arrays and everything else stay untouched.
     */
    private function asTransportValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $value;
    }
}
