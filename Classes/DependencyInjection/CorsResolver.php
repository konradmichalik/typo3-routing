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

namespace KonradMichalik\Typo3Routing\DependencyInjection;

use KonradMichalik\Typo3Routing\Attribute\Cors;
use LogicException;
use ReflectionClass;
use ReflectionMethod;

use function in_array;
use function sprintf;

/**
 * CorsResolver.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class CorsResolver
{
    /**
     * Reads the optional class-level #[Cors], used as the fallback for methods without their own.
     * PHP itself rejects a second non-repeatable #[Cors] on the same class, so no "at most one" check is needed here.
     *
     * @param ReflectionClass<object> $reflection
     */
    public function resolveClass(ReflectionClass $reflection): ?Cors
    {
        $attributes = $reflection->getAttributes(Cors::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }

    /**
     * The method's own #[Cors] wins entirely over the class-level one — it is not merged field by field.
     */
    public function resolveMethod(ReflectionMethod $method, ?Cors $classCors): ?Cors
    {
        $attributes = $method->getAttributes(Cors::class);

        return [] !== $attributes ? $attributes[0]->newInstance() : $classCors;
    }

    /**
     * Validates and stores the resolved #[Cors] into the collected route metadata; a no-op when the
     * route declares none. A wildcard origin combined with credentials would let ANY website read
     * authenticated responses — exactly what the CORS spec's wildcard/credentials prohibition exists to
     * prevent. Unlike the global configuration (which downgrades this at runtime with a warning), a
     * per-route #[Cors] rejects the combination at build time: it is an explicit, deliberate choice,
     * so a misconfiguration should fail loudly.
     */
    public function apply(?Cors $cors, string $name, string $serviceId, string $method, CollectedRoutes $collected): void
    {
        if (null === $cors) {
            return;
        }

        if ($cors->allowCredentials && in_array('*', $cors->allowedOrigins, true)) {
            throw new LogicException(sprintf('#[Cors] on "%s::%s()" (route "%s") combines allowCredentials: true with a wildcard origin ("*"): this would let any website read authenticated responses. List explicit origins instead.', $serviceId, $method, $name), 1750000025);
        }

        $collected->corsConfigs[$name] = [
            'allowedOrigins' => $cors->allowedOrigins,
            'allowedHeaders' => $cors->allowedHeaders,
            'allowCredentials' => $cors->allowCredentials,
            'exposeHeaders' => $cors->exposeHeaders,
            'maxAge' => $cors->maxAge,
        ];
    }
}
