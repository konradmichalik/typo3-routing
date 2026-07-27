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

namespace KonradMichalik\Typo3Routing\Http;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Response;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_scalar;
use function trigger_error;
use function trim;

use const E_USER_WARNING;

/**
 * CorsHandler.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class CorsHandler
{
    /**
     * @var array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}
     */
    private array $defaultPolicy;

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        $cors = [];
        try {
            $config = $extensionConfiguration->get('typo3_routing');
            if (is_array($config) && isset($config['cors']) && is_array($config['cors'])) {
                $cors = $config['cors'];
            }
        } catch (Throwable) {
            // Extension not configured yet — CORS stays disabled.
        }

        $allowedOrigins = $this->toList($this->string($cors, 'allowedOrigins', ''));

        $this->defaultPolicy = [
            'allowedOrigins' => $allowedOrigins,
            'allowedHeaders' => $this->normalizeCsv($this->string($cors, 'allowedHeaders', 'Content-Type, Authorization')),
            'allowCredentials' => $this->resolveAllowCredentials('1' === $this->string($cors, 'allowCredentials', '0'), $allowedOrigins),
            'exposeHeaders' => $this->normalizeCsv($this->string($cors, 'exposeHeaders', '')),
            'maxAge' => (int) $this->string($cors, 'maxAge', '3600'),
        ];
    }

    /**
     * CORS is opt-in: it stays off until at least one allowed origin is configured — globally, or via
     * the resolved route override, which takes over the policy entirely when present.
     *
     * @param array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}|null $routeCors
     */
    public function isEnabled(?array $routeCors = null): bool
    {
        return [] !== $this->resolvePolicy($routeCors)['allowedOrigins'];
    }

    /**
     * Adds the CORS response headers to an actual (non-preflight) response when the request origin is
     * allowed. A disallowed or absent origin leaves the response untouched.
     *
     * @param array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}|null $routeCors
     */
    public function decorate(ResponseInterface $response, ServerRequestInterface $request, ?array $routeCors = null): ResponseInterface
    {
        $policy = $this->resolvePolicy($routeCors);
        if ([] === $policy['allowedOrigins']) {
            return $response;
        }

        $origin = $this->resolveAllowedOrigin($request, $policy);
        if (null === $origin) {
            return $response;
        }

        $response = $this->applyOriginHeaders($response, $origin, $policy);
        if ('' !== $policy['exposeHeaders']) {
            $response = $response->withHeader('Access-Control-Expose-Headers', $policy['exposeHeaders']);
        }

        return $response;
    }

    /**
     * Builds the 204 answer to a CORS preflight (OPTIONS) request. The allowed methods come from the
     * route(s) matching the path; OPTIONS is always added.
     *
     * @param list<string>                                                                                                                 $allowedMethods
     * @param array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}|null $routeCors
     */
    public function preflightResponse(array $allowedMethods, ServerRequestInterface $request, ?array $routeCors = null): ResponseInterface
    {
        $policy = $this->resolvePolicy($routeCors);
        $response = new Response('php://temp', 204);

        $origin = $this->resolveAllowedOrigin($request, $policy);
        if (null !== $origin) {
            $response = $this->applyOriginHeaders($response, $origin, $policy);
        }

        $methods = $allowedMethods;
        if (!in_array('OPTIONS', $methods, true)) {
            $methods[] = 'OPTIONS';
        }

        return $response
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $methods))
            ->withHeader('Access-Control-Allow-Headers', $policy['allowedHeaders'])
            ->withHeader('Access-Control-Max-Age', (string) $policy['maxAge']);
    }

    /**
     * A route's #[Cors] overrides the global configuration entirely (not merged field by field); the
     * compiler pass already rejects allowCredentials + a wildcard origin at build time for it, so
     * unlike the global policy, a route override never needs the runtime credentials downgrade below.
     *
     * @param array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}|null $routeCors
     *
     * @return array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int}
     */
    private function resolvePolicy(?array $routeCors): array
    {
        return $routeCors ?? $this->defaultPolicy;
    }

    /**
     * Credentials require an explicit origin allow-list. Reflecting arbitrary origins with
     * `Access-Control-Allow-Credentials: true` would let ANY website read authenticated API
     * responses — exactly what the spec's wildcard/credentials prohibition exists to prevent —
     * so the wildcard downgrades credentialed CORS to plain wildcard CORS.
     *
     * @param list<string> $allowedOrigins
     */
    private function resolveAllowCredentials(bool $requested, array $allowedOrigins): bool
    {
        if (!$requested || !in_array('*', $allowedOrigins, true)) {
            return $requested;
        }

        trigger_error('typo3_routing: cors.allowCredentials is ignored because cors.allowedOrigins contains "*". List explicit origins to allow credentialed requests.', E_USER_WARNING);

        return false;
    }

    /**
     * @param array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int} $policy
     */
    private function applyOriginHeaders(ResponseInterface $response, string $origin, array $policy): ResponseInterface
    {
        $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
        if ('*' !== $origin) {
            // Responses vary by Origin so shared caches don't serve one origin's headers to another.
            $response = $response->withAddedHeader('Vary', 'Origin');
        }
        if ($policy['allowCredentials']) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    /**
     * The value to echo in Access-Control-Allow-Origin, or null when the request origin is not allowed.
     *
     * @param array{allowedOrigins: list<string>, allowedHeaders: string, allowCredentials: bool, exposeHeaders: string, maxAge: int} $policy
     */
    private function resolveAllowedOrigin(ServerRequestInterface $request, array $policy): ?string
    {
        $origin = $request->getHeaderLine('Origin');
        $origin = '' === $origin ? null : $origin;

        if (in_array('*', $policy['allowedOrigins'], true)) {
            // Credentials are force-disabled for the wildcard (see resolveAllowCredentials), so '*' is always safe here.
            return '*';
        }

        if (null !== $origin && in_array($origin, $policy['allowedOrigins'], true)) {
            return $origin;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function string(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @return list<string>
     */
    private function toList(string $value): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $item): bool => '' !== $item));
    }

    private function normalizeCsv(string $value): string
    {
        return implode(', ', $this->toList($value));
    }
}
