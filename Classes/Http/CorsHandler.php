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
use function trim;

/**
 * CorsHandler.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class CorsHandler
{
    /**
     * @var list<string>
     */
    private array $allowedOrigins;

    private string $allowedHeaders;

    private bool $allowCredentials;

    private string $exposeHeaders;

    private int $maxAge;

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

        $this->allowedOrigins = $this->toList($this->string($cors, 'allowedOrigins', ''));
        $this->allowedHeaders = $this->normalizeCsv($this->string($cors, 'allowedHeaders', 'Content-Type, Authorization'));
        $this->allowCredentials = '1' === $this->string($cors, 'allowCredentials', '0');
        $this->exposeHeaders = $this->normalizeCsv($this->string($cors, 'exposeHeaders', ''));
        $this->maxAge = (int) $this->string($cors, 'maxAge', '3600');
    }

    /**
     * CORS is opt-in: it stays off until at least one allowed origin is configured.
     */
    public function isEnabled(): bool
    {
        return [] !== $this->allowedOrigins;
    }

    /**
     * Adds the CORS response headers to an actual (non-preflight) response when the request origin is
     * allowed. A disallowed or absent origin leaves the response untouched.
     */
    public function decorate(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isEnabled()) {
            return $response;
        }

        $origin = $this->resolveAllowedOrigin($request);
        if (null === $origin) {
            return $response;
        }

        $response = $this->applyOriginHeaders($response, $origin);
        if ('' !== $this->exposeHeaders) {
            $response = $response->withHeader('Access-Control-Expose-Headers', $this->exposeHeaders);
        }

        return $response;
    }

    /**
     * Builds the 204 answer to a CORS preflight (OPTIONS) request. The allowed methods come from the
     * route(s) matching the path; OPTIONS is always added.
     *
     * @param list<string> $allowedMethods
     */
    public function preflightResponse(array $allowedMethods, ServerRequestInterface $request): ResponseInterface
    {
        $response = new Response('php://temp', 204);

        $origin = $this->resolveAllowedOrigin($request);
        if (null !== $origin) {
            $response = $this->applyOriginHeaders($response, $origin);
        }

        $methods = $allowedMethods;
        if (!in_array('OPTIONS', $methods, true)) {
            $methods[] = 'OPTIONS';
        }

        return $response
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $methods))
            ->withHeader('Access-Control-Allow-Headers', $this->allowedHeaders)
            ->withHeader('Access-Control-Max-Age', (string) $this->maxAge);
    }

    private function applyOriginHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
        if ('*' !== $origin) {
            // Responses vary by Origin so shared caches don't serve one origin's headers to another.
            $response = $response->withAddedHeader('Vary', 'Origin');
        }
        if ($this->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    /**
     * The value to echo in Access-Control-Allow-Origin, or null when the request origin is not allowed.
     */
    private function resolveAllowedOrigin(ServerRequestInterface $request): ?string
    {
        $origin = $request->getHeaderLine('Origin');
        $origin = '' === $origin ? null : $origin;

        if (in_array('*', $this->allowedOrigins, true)) {
            // The spec forbids the wildcard together with credentials, so echo the concrete origin then.
            return $this->allowCredentials ? $origin : '*';
        }

        if (null !== $origin && in_array($origin, $this->allowedOrigins, true)) {
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
