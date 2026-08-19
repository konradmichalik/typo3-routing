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

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use KonradMichalik\Typo3Routing\Attribute\DeprecatedRoute;
use LogicException;
use ReflectionClass;
use ReflectionMethod;

use function sprintf;

/**
 * DeprecationResolver.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class DeprecationResolver
{
    /**
     * Reads the optional class-level #[DeprecatedRoute], used as the fallback for methods without
     * their own. PHP itself rejects a second non-repeatable #[DeprecatedRoute] on the same class.
     *
     * @param ReflectionClass<object> $reflection
     */
    public function resolveClass(ReflectionClass $reflection): ?DeprecatedRoute
    {
        $attributes = $reflection->getAttributes(DeprecatedRoute::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }

    /**
     * The method's own #[DeprecatedRoute] wins entirely over the class-level one — not merged field
     * by field, same rule as #[Cors].
     */
    public function resolveMethod(ReflectionMethod $method, ?DeprecatedRoute $classDeprecation): ?DeprecatedRoute
    {
        $attributes = $method->getAttributes(DeprecatedRoute::class);

        return [] !== $attributes ? $attributes[0]->newInstance() : $classDeprecation;
    }

    /**
     * Parses and validates the resolved #[DeprecatedRoute] into the collected route metadata; a no-op
     * when the route declares none. Dates are parsed once here into Unix timestamps, so the dispatcher
     * only ever formats an already-validated instant into the two RFC-mandated header shapes.
     */
    public function apply(?DeprecatedRoute $deprecation, string $name, string $serviceId, string $method, CollectedRoutes $collected): void
    {
        if (null === $deprecation) {
            return;
        }

        $since = $this->parse($deprecation->since, 'since', $name, $serviceId, $method);
        $sunset = null !== $deprecation->sunset ? $this->parse($deprecation->sunset, 'sunset', $name, $serviceId, $method) : null;

        if (null !== $sunset && $sunset < $since) {
            throw new LogicException(sprintf('#[DeprecatedRoute] on "%s::%s()" (route "%s") has a "sunset" earlier than "since": a route cannot stop being supported before it was deprecated.', $serviceId, $method, $name), 1750000032);
        }

        $collected->deprecations[$name] = [
            'since' => $since,
            'sunset' => $sunset,
            'successor' => $deprecation->successor,
            'documentation' => $deprecation->documentation,
        ];
    }

    /**
     * `successor` names a route, and route names are only fully known once every controller has been
     * discovered — the same reason alias-versus-route collisions are checked after the fact.
     */
    public function assertSuccessorsExist(CollectedRoutes $collected): void
    {
        foreach ($collected->deprecations as $name => $deprecation) {
            $successor = $deprecation['successor'];
            if (null !== $successor && !isset($collected->routes[$successor])) {
                throw new LogicException(sprintf('#[DeprecatedRoute] on route "%s" names "%s" as its successor, but no such route is registered.', $name, $successor), 1750000034);
            }
        }
    }

    private function parse(string $value, string $field, string $name, string $serviceId, string $method): int
    {
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Exception) {
            throw new LogicException(sprintf('#[DeprecatedRoute] on "%s::%s()" (route "%s") has an unparseable "%s" value: "%s".', $serviceId, $method, $name, $field, $value), 1750000033);
        }
    }
}
