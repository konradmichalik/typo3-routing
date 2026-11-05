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

namespace KonradMichalik\Typo3Routing\OpenApi;

use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;
use UnitEnum;

use function array_filter;
use function array_values;
use function class_exists;
use function enum_exists;
use function in_array;
use function interface_exists;
use function is_a;
use function is_array;
use function is_string;
use function preg_match;

/**
 * JsonSchemaMapper.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class JsonSchemaMapper
{
    /**
     * @var array<string, string>
     */
    private const CONSTRAINT_KEYWORDS = ['min' => 'minimum', 'max' => 'maximum', 'minLength' => 'minLength', 'maxLength' => 'maxLength'];

    /**
     * Turns an argument's `type` (as `RouteRegistry::getArguments()` reports it) into a JSON Schema
     * fragment. docs/background/extending.md documents the resulting schema per type, and the BC promise.
     *
     * @param string|null                                                               $type        a scalar name (`int`, `float`, `bool`, `array`, `mixed`, `string`), a
     *                                                                                               `class-string<UnitEnum>` of a **backed** enum, a
     *                                                                                               `class-string<DomainObjectInterface>`, or `null` for untyped
     * @param string|null                                                               $pattern     the route's requirement for this argument: applied as `pattern` to a
     *                                                                                               schema that ends up `{"type": "string"}`, and used to narrow a
     *                                                                                               backed enum's `enum` list to the cases the route actually accepts
     * @param array{min?: int|float, max?: int|float, minLength?: int, maxLength?: int} $constraints the argument's value constraints, added as `minimum`, `maximum`, `minLength` and `maxLength`
     *
     * @return array<string, mixed>
     */
    public function schemaForType(?string $type, ?string $pattern = null, array $constraints = []): array
    {
        return [...$this->typeSchema($type, $pattern), ...$this->constraintKeywords($constraints)];
    }

    /**
     * Maps a DTO class's public properties (plain or promoted constructor properties — reflection sees
     * both alike) to an OpenAPI object schema, for `#[Returns(SomeDto::class)]`. A property typed as
     * another class is mapped the same way, recursively, unless it is a backed enum or an Extbase
     * domain object — those go through schemaForType() so the two mappers never disagree about them.
     * A non-nullable property without a default is `required`. A nullable typed property also accepts
     * `null` in its schema, in the JSON Schema 2020-12 form OpenAPI 3.1 uses (see withNull()).
     * A union becomes `anyOf` over its members; an untyped or intersection-typed property is `{}`.
     *
     * A class reached again while it is still being mapped (a self-reference or a cycle) becomes
     * `{"$ref": "#/components/schemas/<ShortName>"}` instead of being inlined forever. Those classes
     * are reported through `$referenced`; the caller has to define each of them under that name.
     *
     * @param class-string       $class
     * @param list<class-string> $referenced
     *
     * @param-out list<class-string> $referenced
     *
     * @return array<string, mixed>
     */
    public function objectSchemaForClass(string $class, array &$referenced = []): array
    {
        $referenced = [];

        return $this->objectSchema($class, [$class], $referenced);
    }

    /**
     * @return array<string, mixed>
     */
    private function typeSchema(?string $type, ?string $pattern): array
    {
        if (null !== $type && is_a($type, UnitEnum::class, true)) {
            return $this->enumSchema($type, $pattern);
        }

        if (null !== $type && is_a($type, DomainObjectInterface::class, true)) {
            return ['type' => 'integer'];
        }

        $schema = match ($type) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => (object) []],
            'mixed' => [],
            // Untyped parameters arrive as raw strings.
            default => ['type' => 'string'],
        };

        if (null !== $pattern && 'string' === ($schema['type'] ?? null)) {
            $schema['pattern'] = $pattern;
        }

        return $schema;
    }

    /**
     * @param array{min?: int|float, max?: int|float, minLength?: int, maxLength?: int} $constraints
     *
     * @return array<string, int|float>
     */
    private function constraintKeywords(array $constraints): array
    {
        $keywords = [];
        foreach (self::CONSTRAINT_KEYWORDS as $key => $keyword) {
            if (isset($constraints[$key])) {
                $keywords[$keyword] = $constraints[$key];
            }
        }

        return $keywords;
    }

    /**
     * @param class-string       $class
     * @param list<class-string> $stack      the classes currently being inlined, outermost first
     * @param list<class-string> $referenced
     *
     * @return array<string, mixed>
     */
    private function objectSchema(string $class, array $stack, array &$referenced): array
    {
        $properties = [];
        $required = [];

        foreach ($this->publicProperties($class) as $property) {
            $name = $property->getName();
            $properties[$name] = $this->unconstrainedAsObject($this->declaredTypeSchema($property->getType(), $property->getDeclaringClass(), $stack, $referenced));

            if ($this->isRequired($property)) {
                $required[] = $name;
            }
        }

        $schema = ['type' => 'object', 'properties' => $this->unconstrainedAsObject($properties)];
        if ([] !== $required) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    private function isRequired(ReflectionProperty $property): bool
    {
        $type = $property->getType();
        if (null === $type || $type->allowsNull()) {
            return false;
        }

        return !($property->isPromoted() ? $this->promotedParameterHasDefault($property) : $property->hasDefaultValue());
    }

    /**
     * @param ReflectionClass<object> $declaring
     * @param list<class-string>      $stack
     * @param list<class-string>      $referenced
     *
     * @return array<string, mixed>
     */
    private function declaredTypeSchema(?ReflectionType $type, ReflectionClass $declaring, array $stack, array &$referenced): array
    {
        if ($type instanceof ReflectionUnionType) {
            $members = [];
            foreach ($type->getTypes() as $member) {
                $members[] = $this->unconstrainedAsObject($this->declaredTypeSchema($member, $declaring, $stack, $referenced));
            }

            return ['anyOf' => $members];
        }

        // Untyped and intersection-typed values have no JSON shape the mapper could state.
        if (!$type instanceof ReflectionNamedType) {
            return [];
        }

        $typeName = $type->getName();
        $schema = $this->namedTypeSchema($this->resolvedTypeName($typeName, $declaring), $stack, $referenced);

        return $type->allowsNull() && 'null' !== $typeName ? $this->withNull($schema) : $schema;
    }

    /**
     * @param ReflectionClass<object> $declaring
     */
    private function resolvedTypeName(string $typeName, ReflectionClass $declaring): string
    {
        // PHP refuses to compile `parent` in a class without one, so the false branch is never taken.
        $parent = $declaring->getParentClass();

        return match (true) {
            'self' === $typeName => $declaring->getName(),
            'parent' === $typeName && false !== $parent => $parent->getName(),
            default => $typeName,
        };
    }

    /**
     * Pseudo types are resolved here because schemaForType() maps request input, where they cannot occur.
     *
     * @param list<class-string> $stack
     * @param list<class-string> $referenced
     *
     * @return array<string, mixed>
     */
    private function namedTypeSchema(string $typeName, array $stack, array &$referenced): array
    {
        return match ($typeName) {
            'null' => ['type' => 'null'],
            'true' => ['const' => true],
            'false' => ['const' => false],
            'object' => ['type' => 'object'],
            // A Traversable is encoded as an object and an array as a list, so no shape fits both.
            'iterable' => [],
            default => $this->propertySchema($typeName, $stack, $referenced),
        };
    }

    /**
     * A named type that is neither a backed enum nor an Extbase domain object but does resolve to a
     * class is treated as a nested DTO and mapped recursively; everything else defers to
     * schemaForType(), so the two mappers never diverge on a scalar, enum, or entity property.
     *
     * @param list<class-string> $stack
     * @param list<class-string> $referenced
     *
     * @return array<string, mixed>
     */
    private function propertySchema(string $typeName, array $stack, array &$referenced): array
    {
        // enum_exists() rather than is_a(): the `UnitEnum` and `BackedEnum` interfaces are no enums.
        if (enum_exists($typeName) || is_a($typeName, DomainObjectInterface::class, true)) {
            return $this->schemaForType($typeName);
        }

        // An interface does not say which properties the serialized object has.
        if (interface_exists($typeName)) {
            return [];
        }

        if (!class_exists($typeName)) {
            return $this->schemaForType($typeName);
        }

        if (!in_array($typeName, $stack, true)) {
            return $this->objectSchema($typeName, [...$stack, $typeName], $referenced);
        }

        if (!in_array($typeName, $referenced, true)) {
            $referenced[] = $typeName;
        }

        return ['$ref' => '#/components/schemas/'.(new ReflectionClass($typeName))->getShortName()];
    }

    /**
     * `json_encode()` writes an empty PHP array as `[]`, but a schema and `properties` must be objects.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>|object
     */
    private function unconstrainedAsObject(array $schema): array|object
    {
        return [] === $schema ? (object) [] : $schema;
    }

    /**
     * A DTO's object schema, a `$ref` or a `const` cannot widen its `type`, so it becomes an `anyOf`; a schema
     * without `type` (`mixed`) already accepts `null`.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function withNull(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (isset($schema['properties']) || isset($schema['$ref']) || isset($schema['const'])) {
            return ['anyOf' => [$schema, ['type' => 'null']]];
        }

        if (!is_string($type)) {
            return $schema;
        }

        $schema['type'] = [$type, 'null'];
        if (is_array($schema['enum'] ?? null)) {
            $schema['enum'][] = null;
        }

        return $schema;
    }

    /**
     * @param class-string $class
     *
     * @return list<ReflectionProperty>
     */
    private function publicProperties(string $class): array
    {
        $properties = (new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC);

        return array_values(array_filter($properties, static fn (ReflectionProperty $property): bool => !$property->isStatic()));
    }

    /**
     * A promoted property has no `hasDefaultValue()` of its own — the default lives on the constructor
     * parameter it promotes, which always exists for a property reflection reports as promoted.
     */
    private function promotedParameterHasDefault(ReflectionProperty $property): bool
    {
        $parameters = $property->getDeclaringClass()->getConstructor()?->getParameters() ?? [];
        $matching = array_values(array_filter($parameters, static fn (ReflectionParameter $parameter): bool => $parameter->getName() === $property->getName()));

        return [] !== $matching && $matching[0]->isDefaultValueAvailable();
    }

    /**
     * @param class-string<UnitEnum> $enum
     *
     * @return array<string, mixed>
     */
    private function enumSchema(string $enum, ?string $pattern): array
    {
        $reflection = new ReflectionEnum($enum);
        $backingType = $reflection->getBackingType()?->getName();

        $values = [];
        foreach ($reflection->getCases() as $case) {
            // A pure enum cannot reach this mapper through the extension's own compile step, which
            // rejects one outright; an external caller passing one gets an empty `enum` list.
            if ($case instanceof ReflectionEnumBackedCase) {
                $values[] = $case->getBackingValue();
            }
        }

        return [
            'type' => 'int' === $backingType ? 'integer' : 'string',
            'enum' => $this->casesMatchingRequirement($values, $pattern),
        ];
    }

    /**
     * Drops the cases a non-empty route requirement rejects, so the `enum` list states what the route
     * accepts rather than what the PHP type allows. Matching mirrors how the requirement is enforced
     * for real — anchored and grouped, exactly as ControllerInvoker and Symfony's matcher apply it.
     *
     * @param list<int|string> $values
     *
     * @return list<int|string>
     */
    private function casesMatchingRequirement(array $values, ?string $pattern): array
    {
        // '' is "presence only" rather than a regex (see #[Route]), so it narrows nothing.
        if (null === $pattern || '' === $pattern) {
            return $values;
        }

        $regex = '#^(?:'.$pattern.')$#';
        if (false === @preg_match($regex, '')) {
            // An unusable requirement cannot be evaluated; report the enum unnarrowed rather than
            // claim no case is acceptable. Such a route never matches anyway — Symfony compiles the
            // broken regex and lets preg_match fail at match time.
            return $values;
        }

        $matching = [];
        foreach ($values as $value) {
            if (1 === preg_match($regex, (string) $value)) {
                $matching[] = $value;
            }
        }

        return $matching;
    }
}
