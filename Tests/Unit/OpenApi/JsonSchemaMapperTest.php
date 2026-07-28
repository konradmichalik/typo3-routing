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
     * The enum branch returns before the pattern is applied, so a string-backed enum carrying a route
     * requirement gets no `pattern` — even though its schema type *is* `string`.
     */
    #[Test]
    public function doesNotApplyPatternToABackedStringEnumSchema(): void
    {
        self::assertSame(
            ['type' => 'string', 'enum' => ['active', 'inactive']],
            (new JsonSchemaMapper())->schemaForType(Status::class, 'active'),
        );
    }
}
