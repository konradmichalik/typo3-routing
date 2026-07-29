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
}
