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

use KonradMichalik\Typo3Routing\Http\RequestBody;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use UnitEnum;

use function array_key_exists;
use function array_map;
use function array_values;
use function in_array;
use function is_a;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function is_subclass_of;
use function preg_match;
use function sprintf;
use function strtolower;

/**
 * ControllerArgumentResolver.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class ControllerArgumentResolver
{
    public function __construct(
        // Lazy: TYPO3's PersistenceManager pulls in PageRepository, whose constructor already
        // queries the DB (a known TYPO3 core issue). RouteDispatcher, the middleware that owns
        // this resolver, runs before page-resolver, so eagerly building it here would break
        // every route, not just ones with an entity parameter.
        #[Lazy] private PersistenceManagerInterface $persistenceManager,
    ) {}

    /**
     * @param list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}> $specs the controller method's parameters, in declaration order
     * @param array<string, mixed>                                                                                           $match the matcher result (path placeholders live here, meta keys are prefixed with "_")
     *
     * @return list<mixed> positional arguments to spread into the controller call
     */
    public function resolve(array $specs, array $match, ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $body = RequestBody::toArray($request);
        $inputs = array_merge($query, $body);

        $arguments = [];
        foreach ($specs as $spec) {
            if ('variadic' === $spec['source']) {
                // A variadic expands a single input array into zero or more positional arguments.
                foreach ($this->resolveVariadic($inputs, $spec) as $value) {
                    $arguments[] = $value;
                }

                continue;
            }

            $arguments[] = match ($spec['source']) {
                'request' => $request,
                // A host placeholder travels in the same match array as a path one.
                'path', 'host' => $this->coerce($match[$spec['name']] ?? null, $spec),
                'query' => $this->resolveInput($query, $spec),
                'body' => $this->resolveInput($body, $spec),
                default => $this->resolveInput($inputs, $spec),
            };
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed>                                                                                     $inputs
     * @param array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed} $spec
     *
     * @return list<mixed>
     */
    private function resolveVariadic(array $inputs, array $spec): array
    {
        if (!array_key_exists($spec['name'], $inputs)) {
            return [];
        }

        $value = $inputs[$spec['name']];
        $items = is_array($value) ? array_values($value) : [$value];

        return array_map(fn (mixed $item): mixed => $this->coerce($item, $spec), $items);
    }

    /**
     * @param array<string, mixed>                                                                                     $inputs
     * @param array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed} $spec
     */
    private function resolveInput(array $inputs, array $spec): mixed
    {
        if (!array_key_exists($spec['name'], $inputs)) {
            return $this->fallbackForMissing($spec);
        }

        return $this->coerce($inputs[$spec['name']], $spec);
    }

    /**
     * @param array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed} $spec
     */
    private function coerce(mixed $value, array $spec): mixed
    {
        if (null === $value) {
            return $this->fallbackForMissing($spec);
        }

        $type = $spec['type'];
        if (null !== $type && is_subclass_of($type, UnitEnum::class, true)) {
            return $this->toEnum($value, $type, $spec['name']);
        }
        if (null !== $type && is_a($type, DomainObjectInterface::class, true)) {
            return $this->toEntity($value, $type, $spec['name']);
        }

        return match ($type) {
            'int' => $this->toInt($value, $spec['name']),
            'float' => $this->toFloat($value, $spec['name']),
            'bool' => $this->toBool($value, $spec['name']),
            'array' => $this->toArray($value, $spec['name']),
            'string' => $this->toString($value, $spec['name']),
            // Untyped or mixed: pass through untouched.
            default => $value,
        };
    }

    /**
     * Resolves an absent value: its default, null when nullable, otherwise a 400.
     *
     * @param array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed} $spec
     */
    private function fallbackForMissing(array $spec): mixed
    {
        if ($spec['hasDefault']) {
            return $spec['default'];
        }
        if ($spec['nullable']) {
            return null;
        }

        throw new ArgumentResolutionException(sprintf('Missing required parameter: %s', $spec['name']), 9685154824);
    }

    /**
     * Maps a scalar input to a backed-enum case by matching its backing value (string-compared, so a
     * "1" from the query resolves an int-backed case). An unknown value yields a 400.
     *
     * @param class-string<UnitEnum> $enum
     */
    private function toEnum(mixed $value, string $enum, string $name): UnitEnum
    {
        if (is_int($value) || is_string($value)) {
            foreach ((new ReflectionEnum($enum))->getCases() as $case) {
                if ($case instanceof ReflectionEnumBackedCase && (string) $case->getBackingValue() === (string) $value) {
                    return $case->getValue();
                }
            }
        }

        throw $this->invalid($name);
    }

    /**
     * Resolves a path/query/body identifier to an Extbase domain object via the persistence manager.
     * A malformed identifier is a 400 (same shape check as `toInt()`); a well-shaped identifier with
     * no matching record is an `EntityNotFoundException`, mapped to a 404 by the dispatcher.
     *
     * @param class-string $class
     */
    private function toEntity(mixed $value, string $class, string $name): object
    {
        if (!is_int($value) && !(is_string($value) && 1 === preg_match('/^-?\d+$/', $value))) {
            throw $this->invalid($name);
        }

        $entity = $this->persistenceManager->getObjectByIdentifier($value, $class);
        if (null === $entity) {
            throw new EntityNotFoundException(sprintf('No "%s" found for identifier "%s".', $class, $value), 7017190502);
        }

        return $entity;
    }

    private function toInt(mixed $value, string $name): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && 1 === preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        throw $this->invalid($name);
    }

    private function toFloat(mixed $value, string $name): float
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        throw $this->invalid($name);
    }

    private function toBool(mixed $value, string $name): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $normalized = strtolower($value);
            if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'off', 'no', ''], true)) {
                return false;
            }
        }

        throw $this->invalid($name);
    }

    /**
     * @return array<mixed>
     */
    private function toArray(mixed $value, string $name): array
    {
        if (is_array($value)) {
            return $value;
        }

        throw $this->invalid($name);
    }

    private function toString(mixed $value, string $name): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        throw $this->invalid($name);
    }

    private function invalid(string $name): ArgumentResolutionException
    {
        return new ArgumentResolutionException(sprintf('Invalid value for parameter: %s', $name));
    }
}
