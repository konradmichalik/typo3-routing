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
use TYPO3\CMS\Core\Core\Environment;

use function array_key_exists;
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
     * exceptions that carry an HTTP meaning onto an error response. Every other exception stays
     * untouched and reaches TYPO3's regular error handling (and its logging).
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
        }
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
}
