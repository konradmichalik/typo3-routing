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
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Entity\Item;
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
    public function mapsExtbaseDomainObjectToItsClassNameForPath(): void
    {
        $spec = $this->build('entityPath', '/api/x/{item}')[0];

        self::assertSame(Item::class, $spec['type']);
        self::assertSame('path', $spec['source']);
    }

    #[Test]
    public function rejectsVariadicEntityParameter(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000009);

        $this->build('variadicEntities', '/api/x');
    }

    #[Test]
    public function appliesParamNameOverride(): void
    {
        $spec = $this->build('renamed', '/api/x')[0];

        self::assertSame('foo', $spec['name']);
        self::assertSame('input', $spec['source']);
    }

    #[Test]
    public function derivesThePathSourceFromTheParamWireNameNotTheParameterName(): void
    {
        // #[Param(name: 'page')] on a {page} placeholder must read the path, not the query.
        $spec = $this->build('renamedToPlaceholder', '/api/blog/{page}')[0];

        self::assertSame('page', $spec['name']);
        self::assertSame('path', $spec['source']);
    }

    #[Test]
    public function hoistsTheDefaultOfARenamedPathPlaceholder(): void
    {
        self::assertSame(['page' => 1], $this->contributions('renamedToPlaceholderWithDefault', '/api/blog/{page}')['defaults']);
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

    #[Test]
    public function hoistsParamRequirementForPathPlaceholder(): void
    {
        self::assertSame(['requirements' => ['id' => '\d+'], 'defaults' => [], 'descriptions' => [], 'optional' => []], $this->contributions('constrainedPath', '/api/x/{id}'));
    }

    #[Test]
    public function hoistsParamRequirementForInput(): void
    {
        self::assertSame(['requirements' => ['q' => '\d+'], 'defaults' => [], 'descriptions' => [], 'optional' => []], $this->contributions('constrainedInput', '/api/x'));
    }

    #[Test]
    public function keysHoistedRequirementByWireNameNotParameterName(): void
    {
        self::assertSame(['requirements' => ['foo' => '\w+'], 'defaults' => [], 'descriptions' => [], 'optional' => []], $this->contributions('constrainedRenamed', '/api/x'));
    }

    #[Test]
    public function hoistsPhpDefaultOfTrailingPathPlaceholder(): void
    {
        self::assertSame(['requirements' => ['page' => '\d+'], 'defaults' => ['page' => 1], 'descriptions' => [], 'optional' => []], $this->contributions('optionalTrailingPath', '/api/blog/{page}'));
    }

    #[Test]
    public function doesNotHoistPhpDefaultOfInputParameter(): void
    {
        self::assertSame(['requirements' => ['page' => '\d+'], 'defaults' => [], 'descriptions' => [], 'optional' => ['page']], $this->contributions('optionalInput', '/api/blog'));
    }

    #[Test]
    public function contributesNothingWithoutParamAttribute(): void
    {
        self::assertSame(['requirements' => [], 'defaults' => [], 'descriptions' => [], 'optional' => []], $this->contributions('unattributedDefault', '/api/blog/{page}'));
    }

    #[Test]
    public function marksAParamConstrainedDefaultedInputAsOptional(): void
    {
        self::assertSame(['page'], $this->contributions('optionalInput', '/api/blog')['optional']);
    }

    #[Test]
    public function doesNotMarkAnInputOptionalWithoutAParamRequirement(): void
    {
        // A #[Param] that only renames or documents contributes no optionality: the requirement
        // would then be the route's own, which stays mandatory.
        self::assertSame([], $this->contributions('describedParam', '/api/blog/{page}')['optional']);
    }

    #[Test]
    public function doesNotMarkAPathPlaceholderAsOptionalInput(): void
    {
        // A path placeholder is enforced by the matcher, never by the input check.
        self::assertSame([], $this->contributions('optionalTrailingPath', '/api/blog/{page}')['optional']);
    }

    #[Test]
    public function collectsParamDescription(): void
    {
        self::assertSame(['requirements' => [], 'defaults' => ['page' => 1], 'descriptions' => ['page' => 'Page number, 1-based.'], 'optional' => []], $this->contributions('describedParam', '/api/blog/{page}'));
    }

    #[Test]
    public function keysADescriptionByWireNameNotParameterName(): void
    {
        self::assertSame(['page' => 'Page number, 1-based.'], $this->contributions('describedParam', '/api/blog/{page}')['descriptions']);
        self::assertSame(['q' => 'Free-text search term.'], $this->contributions('describedRenamed', '/api/x')['descriptions']);
    }

    #[Test]
    public function rejectsDefaultOnNonTrailingPathPlaceholder(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000027);

        $this->contributions('optionalLeadingPath', '/api/x/{page}/{slug}');
    }

    #[Test]
    public function rejectsARequirementAlsoDeclaredOnTheRoute(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000029);

        $reflection = new ReflectionMethod(ArgumentSpecFixtures::class, 'constrainedPath');

        $this->factory->paramContributions($reflection, $this->build('constrainedPath', '/api/x/{id}'), '/api/x/{id}', ['id' => '\d+'], [], 'fixtures');
    }

    #[Test]
    public function derivesTheErrorLocationFromTheServiceId(): void
    {
        $this->expectExceptionMessage('"fixtures::presenceOnlyWithDefault()"');

        $this->contributions('presenceOnlyWithDefault', '/api/x');
    }

    #[Test]
    public function rejectsADefaultAlsoDeclaredOnTheRoute(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000030);

        $reflection = new ReflectionMethod(ArgumentSpecFixtures::class, 'optionalTrailingPath');

        $this->factory->paramContributions($reflection, $this->build('optionalTrailingPath', '/api/blog/{page}'), '/api/blog/{page}', [], ['page' => 5], 'fixtures');
    }

    #[Test]
    public function rejectsPresenceOnlyRequirementOnDefaultedParameter(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000028);

        $this->contributions('presenceOnlyWithDefault', '/api/x');
    }

    #[Test]
    public function derivesTheHostSourceForAHostPlaceholder(): void
    {
        $specs = $this->build('hostBound', '/api/status', '{subdomain}.example.com');

        self::assertSame(['name' => 'subdomain', 'type' => 'string', 'source' => 'host', 'nullable' => false, 'hasDefault' => false, 'default' => null], $specs[0]);
        self::assertSame('input', $specs[1]['source']);
    }

    #[Test]
    public function derivesThePathSourceForANonAsciiPlaceholderName(): void
    {
        self::assertSame('path', $this->build('nonAsciiPlaceholder', '/api/coins/{münze}')[0]['source']);
    }

    #[Test]
    public function keepsAHostPlaceholderRequiredDespiteAParameterDefault(): void
    {
        $contributions = $this->contributions('hostBoundWithDefault', '/api/status', '{subdomain}.example.com');

        self::assertSame(['subdomain' => '\w+'], $contributions['requirements']);
        // The optional-trailing-placeholder rule is a path concept: a host placeholder always matches,
        // so its PHP default never turns it into an optional route default or an optional input.
        self::assertSame([], $contributions['defaults']);
        self::assertSame([], $contributions['optional']);
    }

    /**
     * @return list<array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed}>
     */
    private function build(string $method, string $path, ?string $host = null): array
    {
        return $this->factory->build(new ReflectionMethod(ArgumentSpecFixtures::class, $method), $path, 'fixtures', $host);
    }

    /**
     * @return array{requirements: array<string, string>, defaults: array<string, mixed>, descriptions: array<string, string>, optional: list<string>}
     */
    private function contributions(string $method, string $path, ?string $host = null): array
    {
        $reflection = new ReflectionMethod(ArgumentSpecFixtures::class, $method);

        return $this->factory->paramContributions($reflection, $this->build($method, $path, $host), $path, [], [], 'fixtures');
    }
}
