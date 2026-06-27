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

namespace KonradMichalik\Typo3Routing\Tests\Unit\DependencyInjection;

use KonradMichalik\Typo3Routing\DependencyInjection\ArgumentSpecFactory;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\ArgumentSpecFixtures;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Enum\{Priority, Status};
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * ArgumentSpecFactoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(ArgumentSpecFactory::class)]
final class ArgumentSpecFactoryTest extends TestCase
{
    private ArgumentSpecFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ArgumentSpecFactory();
    }

    #[Test]
    public function derivesSourceTypeNullabilityAndDefaultsForScalars(): void
    {
        $specs = $this->build('scalars', '/api/scalars/{id}');

        self::assertSame(['name' => 'id', 'type' => 'int', 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null], $specs[0]);
        self::assertSame(['name' => 'q', 'type' => 'string', 'source' => 'input', 'nullable' => false, 'hasDefault' => false, 'default' => null], $specs[1]);
        self::assertSame(['name' => 'active', 'type' => 'bool', 'source' => 'input', 'nullable' => false, 'hasDefault' => true, 'default' => false], $specs[2]);
        self::assertSame(['name' => 'request', 'type' => null, 'source' => 'request', 'nullable' => true, 'hasDefault' => true, 'default' => null], $specs[3]);
    }

    #[Test]
    public function mapsBackedEnumToItsClassNameForPathAndInput(): void
    {
        $pathSpec = $this->build('enumPath', '/api/x/{priority}')[0];
        self::assertSame(Priority::class, $pathSpec['type']);
        self::assertSame('path', $pathSpec['source']);

        $inputSpec = $this->build('enumInput', '/api/x')[0];
        self::assertSame(Status::class, $inputSpec['type']);
        self::assertSame('input', $inputSpec['source']);
    }

    #[Test]
    public function rejectsPureEnum(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000006);

        $this->build('pure', '/api/x');
    }

    #[Test]
    public function marksVariadicWithItsElementType(): void
    {
        $intSpec = $this->build('variadicInts', '/api/x')[0];
        self::assertSame(['name' => 'ids', 'type' => 'int', 'source' => 'variadic', 'nullable' => false, 'hasDefault' => false, 'default' => null], $intSpec);

        $enumSpec = $this->build('variadicEnums', '/api/x')[0];
        self::assertSame(Status::class, $enumSpec['type']);
        self::assertSame('variadic', $enumSpec['source']);
    }

    #[Test]
    public function appliesParamNameOverride(): void
    {
        $spec = $this->build('renamed', '/api/x')[0];

        self::assertSame('foo', $spec['name']);
        self::assertSame('input', $spec['source']);
    }

    #[Test]
    public function appliesParamSourceOverride(): void
    {
        $spec = $this->build('sourced', '/api/x')[0];

        self::assertSame('query', $spec['source']);
    }

    #[Test]
    public function rejectsUnknownParamSource(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000007);

        $this->build('bogusSource', '/api/x');
    }

    #[Test]
    public function rejectsSourceOverrideOnVariadic(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000008);

        $this->build('variadicSourced', '/api/x');
    }

    #[Test]
    public function rejectsUnionType(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000003);

        $this->build('unionType', '/api/x');
    }

    #[Test]
    public function rejectsUnsupportedObjectType(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000004);

        $this->build('unsupportedObject', '/api/x');
    }

    #[Test]
    public function rejectsUnsupportedScalarType(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000005);

        $this->build('unsupportedScalar', '/api/x');
    }

    /**
     * @return list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>
     */
    private function build(string $method, string $path): array
    {
        return $this->factory->build(new ReflectionMethod(ArgumentSpecFixtures::class, $method), $path, 'fixtures');
    }
}
