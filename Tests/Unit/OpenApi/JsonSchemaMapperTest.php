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

namespace KonradMichalik\Typo3Routing\Tests\Unit\OpenApi;

use KonradMichalik\Typo3Routing\OpenApi\JsonSchemaMapper;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Dto\{AllOptionalDto, ChildDto, CourseDto, CourseWithInstructorDto, CycleADto, CycleHolderDto, EmptyDto, LooseFieldsDto, NodeDto, NullableFieldsDto, SelfTypedNodeDto};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Entity\Item;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Enum\{Priority, Status, Suit};
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;

/**
 * JsonSchemaMapperTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(JsonSchemaMapper::class)]
final class JsonSchemaMapperTest extends TestCase
{
    /**
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('scalarTypes')]
    public function mapsScalarTypeToSchema(?string $type, array $expected): void
    {
        self::assertSame($expected, (new JsonSchemaMapper())->schemaForType($type));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: array<string, mixed>}>
     */
    public static function scalarTypes(): iterable
    {
        yield 'int' => ['int', ['type' => 'integer']];
        yield 'float' => ['float', ['type' => 'number']];
        yield 'bool' => ['bool', ['type' => 'boolean']];
        yield 'string' => ['string', ['type' => 'string']];
        yield 'mixed constrains nothing' => ['mixed', []];
        yield 'untyped falls back to string' => [null, ['type' => 'string']];
        yield 'unrecognised type falls back to string' => ['DateTimeImmutable', ['type' => 'string']];
    }

    #[Test]
    public function mapsTheRangeOfAnArgumentToMinimumAndMaximum(): void
    {
        self::assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 100], (new JsonSchemaMapper())->schemaForType('int', null, ['min' => 1, 'max' => 100]));
    }

    #[Test]
    public function mapsTheLengthsOfAnArgumentNextToItsPatternIgnoringTheOtherSpecKeys(): void
    {
        $argument = ['name' => 'q', 'type' => 'string', 'source' => 'input', 'nullable' => false, 'hasDefault' => false, 'default' => null, 'minLength' => 2, 'maxLength' => 200];

        self::assertSame(
            ['type' => 'string', 'pattern' => '\w+', 'minLength' => 2, 'maxLength' => 200],
            (new JsonSchemaMapper())->schemaForType('string', '\w+', $argument),
        );
    }

    #[Test]
    public function mapsArrayToSchemaWithAnEmptyObjectAsItems(): void
    {
        $schema = (new JsonSchemaMapper())->schemaForType('array');

        self::assertSame('array', $schema['type']);
        // `items` must serialise as `{}`, not `[]` — an empty array would encode as a JSON array.
        self::assertEquals((object) [], $schema['items']);
        self::assertSame('{"type":"array","items":{}}', json_encode($schema));
    }

    #[Test]
    public function mapsBackedIntEnumToAnIntegerEnumSchema(): void
    {
        self::assertSame(
            ['type' => 'integer', 'enum' => [1, 5]],
            (new JsonSchemaMapper())->schemaForType(Priority::class),
        );
    }

    #[Test]
    public function mapsBackedStringEnumToAStringEnumSchema(): void
    {
        self::assertSame(
            ['type' => 'string', 'enum' => ['active', 'inactive']],
            (new JsonSchemaMapper())->schemaForType(Status::class),
        );
    }

    /**
     * A pure enum cannot reach the mapper through the extension's own compile step — ArgumentSpecFactory
     * rejects one. Pinned because `@api` widens the input domain to external callers.
     */
    #[Test]
    public function mapsPureEnumToAStringSchemaWithoutValues(): void
    {
        self::assertSame(
            ['type' => 'string', 'enum' => []],
            (new JsonSchemaMapper())->schemaForType(Suit::class),
        );
    }

    /**
     * Such an argument is resolved from a record UID (ControllerArgumentResolver::toEntity() accepts
     * nothing else), so `integer` describes the wire value, not the entity.
     */
    #[Test]
    public function mapsAnExtbaseDomainObjectToAnIntegerUidSchema(): void
    {
        self::assertSame(['type' => 'integer'], (new JsonSchemaMapper())->schemaForType(Item::class));
    }

    #[Test]
    public function doesNotApplyPatternToADomainObjectSchema(): void
    {
        $schema = (new JsonSchemaMapper())->schemaForType(Item::class, '\d+');

        self::assertArrayNotHasKey('pattern', $schema);
    }

    #[Test]
    public function appliesPatternToAStringSchema(): void
    {
        self::assertSame(
            ['type' => 'string', 'pattern' => '[a-z]+'],
            (new JsonSchemaMapper())->schemaForType('string', '[a-z]+'),
        );
    }

    #[Test]
    public function appliesPatternToAnUntypedArgument(): void
    {
        self::assertSame(
            ['type' => 'string', 'pattern' => '\d+'],
            (new JsonSchemaMapper())->schemaForType(null, '\d+'),
        );
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('nonStringSchemaTypes')]
    public function doesNotApplyPatternToANonStringSchema(?string $type, array $expected): void
    {
        self::assertSame($expected, (new JsonSchemaMapper())->schemaForType($type, '\d+'));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: array<string, mixed>}>
     */
    public static function nonStringSchemaTypes(): iterable
    {
        yield 'int' => ['int', ['type' => 'integer']];
        yield 'float' => ['float', ['type' => 'number']];
        yield 'bool' => ['bool', ['type' => 'boolean']];
        // `mixed` has no `type` at all, so the string check cannot match.
        yield 'mixed' => ['mixed', []];
    }

    #[Test]
    public function doesNotApplyPatternToAnArraySchema(): void
    {
        $schema = (new JsonSchemaMapper())->schemaForType('array', '\d+');

        self::assertArrayNotHasKey('pattern', $schema);
    }

    /**
     * A requirement narrows the `enum` list instead of becoming a `pattern`: after narrowing, `enum`
     * states the accepted values exactly, so a second keyword saying the same thing would be noise.
     */
    #[Test]
    public function narrowsABackedStringEnumToTheCasesTheRequirementAccepts(): void
    {
        self::assertSame(
            ['type' => 'string', 'enum' => ['active']],
            (new JsonSchemaMapper())->schemaForType(Status::class, 'active'),
        );
    }

    #[Test]
    public function doesNotAddAPatternKeyWhenNarrowingAnEnum(): void
    {
        $schema = (new JsonSchemaMapper())->schemaForType(Status::class, 'active');

        self::assertArrayNotHasKey('pattern', $schema);
    }

    #[Test]
    public function keepsEveryCaseWhenTheRequirementAcceptsThemAll(): void
    {
        self::assertSame(
            ['type' => 'string', 'enum' => ['active', 'inactive']],
            (new JsonSchemaMapper())->schemaForType(Status::class, '[a-z]+'),
        );
    }

    /**
     * Alternation must group: an unanchored `^active|inactive$` reading would keep both cases for the
     * wrong reason. Symfony wraps the requirement in a group, and so does the mapper.
     */
    #[Test]
    public function narrowsUsingGroupedAlternation(): void
    {
        self::assertSame(
            ['type' => 'string', 'enum' => ['active', 'inactive']],
            (new JsonSchemaMapper())->schemaForType(Status::class, 'active|inactive'),
        );
    }

    /**
     * The requirement is a full match, not a search — Symfony compiles it into an anchored regex, so a
     * case merely *containing* the requirement is rejected.
     */
    #[Test]
    public function narrowsWithFullMatchesOnly(): void
    {
        self::assertSame(
            ['type' => 'string', 'enum' => []],
            (new JsonSchemaMapper())->schemaForType(Status::class, 'activ'),
        );
    }

    #[Test]
    public function narrowsABackedIntEnumAgainstTheStringFormOfItsValues(): void
    {
        self::assertSame(
            ['type' => 'integer', 'enum' => [5]],
            (new JsonSchemaMapper())->schemaForType(Priority::class, '5'),
        );
    }

    /**
     * A requirement excluding every case describes a route that can never match. The empty list says
     * so rather than overstating what is accepted.
     */
    #[Test]
    public function yieldsAnEmptyEnumWhenNoCaseSatisfiesTheRequirement(): void
    {
        self::assertSame(
            ['type' => 'string', 'enum' => []],
            (new JsonSchemaMapper())->schemaForType(Status::class, 'pending'),
        );
    }

    /**
     * '' means "presence only" rather than a regex (see #[Route]), so it narrows nothing — matching
     * how ControllerInvoker skips pattern validation for it.
     */
    #[Test]
    public function treatsAnEmptyRequirementAsNoNarrowing(): void
    {
        self::assertSame(
            ['type' => 'string', 'enum' => ['active', 'inactive']],
            (new JsonSchemaMapper())->schemaForType(Status::class, ''),
        );
    }

    /**
     * An unusable regex cannot be evaluated, so the enum stays unnarrowed and — crucially — no PHP
     * warning escapes into what is a read-only describe operation.
     */
    #[Test]
    public function keepsEveryCaseWhenTheRequirementIsNotAUsableRegex(): void
    {
        self::assertSame(
            ['type' => 'string', 'enum' => ['active', 'inactive']],
            (new JsonSchemaMapper())->schemaForType(Status::class, '[unclosed'),
        );
    }

    #[Test]
    public function narrowingIsUnaffectedByPureEnumsHavingNoValues(): void
    {
        self::assertSame(
            ['type' => 'string', 'enum' => []],
            (new JsonSchemaMapper())->schemaForType(Suit::class, 'Hearts'),
        );
    }

    #[Test]
    public function mapsADtosPromotedPropertiesToAnObjectSchema(): void
    {
        $schema = (new JsonSchemaMapper())->objectSchemaForClass(CourseDto::class);

        self::assertSame('object', $schema['type']);
        self::assertSame(
            [
                'id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'description' => ['type' => ['string', 'null']],
                'enrolledCount' => ['type' => 'integer'],
            ],
            $schema['properties'],
        );
    }

    /**
     * A nullable property or one with a default is optional; everything else is required.
     */
    #[Test]
    public function marksOnlyNonNullableParameterlessPropertiesAsRequired(): void
    {
        $schema = (new JsonSchemaMapper())->objectSchemaForClass(CourseDto::class);

        self::assertSame(['id', 'title'], $schema['required']);
    }

    #[Test]
    public function omitsRequiredEntirelyWhenEveryPropertyIsOptional(): void
    {
        $schema = (new JsonSchemaMapper())->objectSchemaForClass(AllOptionalDto::class);

        self::assertArrayNotHasKey('required', $schema);
    }

    #[Test]
    public function mapsANestedDtoPropertyRecursivelyAndAnEnumPropertyThroughSchemaForType(): void
    {
        $schema = (new JsonSchemaMapper())->objectSchemaForClass(CourseWithInstructorDto::class);

        self::assertSame(
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']], 'required' => ['id', 'name']],
            $schema['properties']['instructor'],
        );
        self::assertSame(['type' => 'string', 'enum' => ['active', 'inactive']], $schema['properties']['status']);
        self::assertSame(['id', 'instructor', 'status'], $schema['required']);
    }

    /**
     * Optional and nullable are different things: a missing key and an explicit `null` are two
     * distinct cases, so a nullable property's schema has to accept `null` itself.
     *
     * @param array<string, mixed>|object $expected
     */
    #[Test]
    #[DataProvider('nullablePropertyProvider')]
    public function includesNullInTheSchemaOfANullableProperty(string $property, array|object $expected): void
    {
        $schema = (new JsonSchemaMapper())->objectSchemaForClass(NullableFieldsDto::class);

        // Compared as JSON because `items` is an `stdClass`, which assertSame() compares by identity.
        self::assertSame(json_encode($expected), json_encode($schema['properties'][$property]));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>|object}>
     */
    public static function nullablePropertyProvider(): iterable
    {
        yield 'non-nullable scalar stays as it was' => ['name', ['type' => 'string']];
        yield 'nullable scalar' => ['title', ['type' => ['string', 'null']]];
        yield 'nullable string enum' => ['status', ['type' => ['string', 'null'], 'enum' => ['active', 'inactive', null]]];
        // An object schema with `properties` cannot simply widen its `type`.
        yield 'nullable nested DTO' => ['instructor', ['anyOf' => [
            ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']], 'required' => ['id', 'name']],
            ['type' => 'null'],
        ]]];
        yield 'nullable array keeps its items' => ['tags', ['type' => ['array', 'null'], 'items' => (object) []]];
        // `mixed` has no `type`, so it already accepts null.
        yield 'mixed stays unconstrained' => ['anything', (object) []];
        // A response value is not a raw query string, so nothing is known about an untyped one.
        yield 'untyped is unconstrained' => ['legacy', (object) []];
    }

    /**
     * @param array<string, mixed>|object $expected
     */
    #[Test]
    #[DataProvider('looseTypePropertyProvider')]
    public function mapsUnionIntersectionUntypedAndPseudoTypedProperties(string $property, array|object $expected): void
    {
        $schema = (new JsonSchemaMapper())->objectSchemaForClass(LooseFieldsDto::class);

        self::assertSame(json_encode($expected), json_encode($schema['properties'][$property]));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>|object}>
     */
    public static function looseTypePropertyProvider(): iterable
    {
        yield 'nullable union lists null once' => ['ref', ['anyOf' => [['type' => 'string'], ['type' => 'integer'], ['type' => 'null']]]];
        yield 'union' => ['code', ['anyOf' => [['type' => 'string'], ['type' => 'integer']]]];
        yield 'false member is a constant' => ['label', ['anyOf' => [['type' => 'string'], ['const' => false]]]];
        yield 'true member is a constant' => ['flag', ['anyOf' => [['type' => 'integer'], ['const' => true]]]];
        yield 'nullable false' => ['off', ['anyOf' => [['const' => false], ['type' => 'null']]]];
        yield 'nullable true' => ['on', ['anyOf' => [['const' => true], ['type' => 'null']]]];
        yield 'BackedEnum interface' => ['backed', (object) []];
        yield 'UnitEnum interface' => ['unit', (object) []];
        yield 'object' => ['meta', ['type' => 'object']];
        yield 'nullable object' => ['extra', ['type' => ['object', 'null']]];
        // A Traversable is encoded as an object, an array as a list, so nothing is known.
        yield 'iterable' => ['items', (object) []];
        yield 'intersection' => ['both', (object) []];
        yield 'intersection inside a union' => ['either', ['anyOf' => [(object) [], ['type' => 'null']]]];
        yield 'interface' => ['payload', (object) []];
    }

    #[Test]
    public function requiresOnlyTheNonNullablePropertiesWithoutDefaultOfALooseDto(): void
    {
        $schema = (new JsonSchemaMapper())->objectSchemaForClass(LooseFieldsDto::class);

        self::assertSame(['meta', 'both', 'payload'], $schema['required']);
    }

    #[Test]
    public function encodesAnEmptyDtoAsAJsonObject(): void
    {
        self::assertSame('{"type":"object","properties":{}}', json_encode((new JsonSchemaMapper())->objectSchemaForClass(EmptyDto::class)));
    }

    #[Test]
    public function referencesADirectlySelfReferencingDtoInsteadOfRecursing(): void
    {
        $referenced = [];

        $schema = (new JsonSchemaMapper())->objectSchemaForClass(NodeDto::class, $referenced);

        self::assertSame(
            ['anyOf' => [['$ref' => '#/components/schemas/NodeDto'], ['type' => 'null']]],
            $schema['properties']['parent'],
        );
        self::assertSame([NodeDto::class], $referenced);
    }

    #[Test]
    public function resolvesSelfToTheDeclaringClass(): void
    {
        $referenced = [];

        $schema = (new JsonSchemaMapper())->objectSchemaForClass(SelfTypedNodeDto::class, $referenced);

        self::assertSame(
            ['anyOf' => [['$ref' => '#/components/schemas/SelfTypedNodeDto'], ['type' => 'null']]],
            $schema['properties']['next'],
        );
        self::assertSame([SelfTypedNodeDto::class], $referenced);
    }

    #[Test]
    public function resolvesParentToTheDeclaringClassesParent(): void
    {
        $schema = (new JsonSchemaMapper())->objectSchemaForClass(ChildDto::class);

        self::assertSame(
            ['anyOf' => [['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]], ['type' => 'null']]],
            $schema['properties']['base'],
        );
    }

    #[Test]
    public function inlinesACycleUntilItReachesAClassAlreadyBeingMapped(): void
    {
        $referenced = [];

        $schema = (new JsonSchemaMapper())->objectSchemaForClass(CycleHolderDto::class, $referenced);

        self::assertSame(
            ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/components/schemas/CycleADto']], 'required' => ['a']],
            $schema['properties']['a']['properties']['b']['anyOf'][0],
        );
        self::assertSame([CycleADto::class], $referenced);
    }

    #[Test]
    public function reportsNoReferencedClassesForAnAcyclicDto(): void
    {
        $referenced = [NodeDto::class];

        (new JsonSchemaMapper())->objectSchemaForClass(CourseWithInstructorDto::class, $referenced);

        self::assertSame([], $referenced);
    }
}
