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

use KonradMichalik\Typo3Routing\Http\{HttpProblemException, JsonErrorResponse, RequestBody};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Throwable;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\ImmediateResponseException;

use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_map;
use function assert;
use function explode;
use function in_array;
use function is_array;
use function is_object;
use function is_string;
use function sprintf;

/**
 * ControllerInvoker.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class ControllerInvoker
{
    public function __construct(
        private RouteRegistry $registry,
        private ControllerArgumentResolver $argumentResolver,
    ) {}

    /**
     * Resolves the controller's arguments from the match and the request, calls it, and maps the
     * exceptions that carry an HTTP meaning onto an error response. A route whose controller method
     * declares a bare `JsonResponse` return type (see RouteRegistry::isJsonErrorRoute()) also converts
     * every other exception into a generic JSON error; every other route leaves them untouched, to
     * reach TYPO3's regular error handling (and its logging) exactly as before. TYPO3's own
     * `ImmediateResponseException` is never converted either way: it is not an error, but a
     * response-carrying control-flow signal that must reach `AbstractApplication` unchanged.
     *
     * @param array<string, mixed> $match
     */
    public function invoke(array $match, ServerRequestInterface $request): ResponseInterface
    {
        $routeName = (string) ($match['_route'] ?? '');
        [$serviceId, $method] = explode('::', (string) $match['_controller'], 2);
        $controller = $this->registry->getControllerLocator()->get($serviceId);
        assert(is_object($controller));

        $request = $this->withPathAttributes($match, $request);

        try {
            $arguments = $this->argumentResolver->resolve($this->registry->getArguments($routeName), $match, $request);

            /** @var callable(mixed...): ResponseInterface $target */
            $target = [$controller, $method];

            return $target(...$arguments);
        } catch (ArgumentResolutionException $exception) {
            return JsonErrorResponse::create(400, $exception->getMessage());
        } catch (EntityNotFoundException) {
            return JsonErrorResponse::create(404, 'Not Found');
        } catch (HttpProblemException $exception) {
            return JsonErrorResponse::create($exception->status, $exception->getMessage());
        } catch (ImmediateResponseException $exception) {
            // Not an error: TYPO3 core's own signal to short-circuit with a specific response
            // (a redirect, for instance). Must reach AbstractApplication unconverted, JSON-error
            // route or not.
            throw $exception;
        } catch (Throwable $exception) {
            if (!$this->registry->isJsonErrorRoute($routeName)) {
                throw $exception;
            }

            // Outside Development, never the exception's own message or trace: this path exists
            // specifically for exceptions nobody anticipated, so its detail is unvetted and
            // possibly sensitive. In Development, surfacing it is the whole point.
            if (Environment::getContext()->isDevelopment()) {
                return JsonErrorResponse::create(500, $exception->getMessage(), extra: $this->exceptionDebugExtra($exception));
            }

            return JsonErrorResponse::create(500, 'Internal Server Error');
        }
    }

    /**
     * A malformed/wrong-shaped JSON body, or a body sent under a content type this extension cannot
     * read, on a route that actually reads from the body. Checked before requirement and argument
     * resolution so the response names the real cause instead of a derived "missing parameter" — a
     * route that never reads from the body is unaffected by its content type entirely.
     *
     * @param array<string, mixed> $match
     */
    public function firstRequestBodyError(array $match, ServerRequestInterface $request): ?ResponseInterface
    {
        if (!$this->bindsBody((string) ($match['_route'] ?? ''))) {
            return null;
        }

        if (RequestBody::isUnsupportedMediaType($request)) {
            return JsonErrorResponse::create(415, 'Unsupported request body content type', ['Accept-Post' => 'application/json']);
        }

        $detail = RequestBody::decodeErrorDetail($request);

        return null !== $detail ? JsonErrorResponse::create(400, $detail) : null;
    }

    /**
     * Validates `requirements` whose name is not a matched path placeholder against the query and parsed
     * body: a missing parameter or a value violating the regex yields a 400.
     *
     * A key the compiler marked optional — its requirement came from a #[Param] on a parameter with a
     * PHP default — is "optional but constrained": its absence falls back to that default instead of
     * being an error, while a value that is present is still checked against the pattern. A
     * requirement declared on the #[Route] itself stays mandatory, PHP default or not.
     *
     * @param array<string, mixed> $match
     */
    public function firstInputRequirementError(array $match, ServerRequestInterface $request): ?string
    {
        $requirements = $match['_requirements'] ?? null;
        $inputs = array_merge($request->getQueryParams(), RequestBody::toArray($request));
        $optional = $this->registry->getOptionalInputs((string) ($match['_route'] ?? ''));

        foreach (is_array($requirements) ? $requirements : [] as $name => $pattern) {
            $key = (string) $name;
            // A matched path placeholder is already validated by the matcher.
            if (array_key_exists($key, $match)) {
                continue;
            }
            if (!array_key_exists($key, $inputs)) {
                if (in_array($key, $optional, true)) {
                    continue;
                }

                return sprintf('Missing required parameter: %s', $key);
            }
            if (is_string($pattern) && $this->inputViolatesPattern($pattern, $inputs[$key])) {
                return sprintf('Invalid value for parameter: %s', $key);
            }
        }

        return null;
    }

    /**
     * Whether a route bound to an application context is visible right now. An env-less route
     * (null or empty) is visible everywhere.
     */
    public function isVisibleInCurrentContext(mixed $env): bool
    {
        if (!is_string($env) || '' === $env) {
            return true;
        }

        $current = explode('/', (string) Environment::getContext())[0];

        return strtolower($current) === strtolower($env);
    }

    /**
     * RFC 9457 extension members for a Development-context 500: exception class, code, origin and
     * trace. Frame `args` are deliberately dropped — argument values can carry secrets (passwords,
     * tokens) that Development doesn't excuse exposing in an HTTP response.
     *
     * @return array<string, mixed>
     */
    private function exceptionDebugExtra(Throwable $exception): array
    {
        return [
            'exception' => $exception::class,
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => array_map(
                static fn (array $frame): array => array_intersect_key($frame, array_flip(['file', 'line', 'function', 'class', 'type'])),
                $exception->getTrace(),
            ),
        ];
    }

    /**
     * Path placeholders stay available as request attributes for controllers that take the request.
     *
     * @param array<string, mixed> $match
     */
    private function withPathAttributes(array $match, ServerRequestInterface $request): ServerRequestInterface
    {
        foreach ($match as $key => $value) {
            if (!str_starts_with($key, '_')) {
                $request = $request->withAttribute($key, $value);
            }
        }

        return $request;
    }

    /**
     * A non-empty pattern is violated when the value is not a string or does not fully match the regex.
     */
    private function inputViolatesPattern(string $pattern, mixed $value): bool
    {
        return '' !== $pattern && (!is_string($value) || 1 !== preg_match('#^(?:'.$pattern.')$#', $value));
    }

    /**
     * Whether any of the route's controller arguments can resolve from the body: explicitly (`body`),
     * merged with the query (`input`), or spread from it (`variadic`) — as opposed to `path`, `query`,
     * or the request itself, none of which a request body's content type could ever affect.
     */
    private function bindsBody(string $routeName): bool
    {
        foreach ($this->registry->getArguments($routeName) as $spec) {
            if (in_array($spec['source'], ['body', 'input', 'variadic'], true)) {
                return true;
            }
        }

        return false;
    }
}
