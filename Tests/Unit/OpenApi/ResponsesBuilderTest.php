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

use KonradMichalik\Typo3Routing\OpenApi\{JsonSchemaMapper, ResponsesBuilder};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Dto\Collision\CourseDto as CollidingCourseDto;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Dto\CourseDto;
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;

/**
 * ResponsesBuilderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(ResponsesBuilder::class)]
final class ResponsesBuilderTest extends TestCase
{
    /**
     * 404 is always present — a path claimed by no route in the collection answers 404 regardless of
     * any other feature the route opted into.
     */
    #[Test]
    public function producesOnlyTheGenericSuccessAndThe404WithoutAnyDeclarationOrCondition(): void
    {
        $usedSchemas = [];

        $responses = $this->builder()->build([], false, false, false, false, false, $usedSchemas);

        self::assertSame([200, 404], array_keys($responses));
        self::assertSame('Successful response', $responses[200]['description']);
    }

    #[Test]
    public function addsEveryGeneratorDerivedStatusWhenItsConditionIsTrue(): void
    {
        $usedSchemas = [];

        $responses = $this->builder()->build([], true, true, true, true, true, $usedSchemas);

        self::assertSame([200, 400, 401, 403, 404, 405, 429], array_keys($responses));
    }

    #[Test]
    public function aDeclaredEntryOverridesTheGenericStatusInsteadOfDuplicatingIt(): void
    {
        $usedSchemas = [];
        $declared = [['status' => 200, 'schema' => null, 'collection' => false, 'description' => 'Custom success']];

        $responses = $this->builder()->build($declared, false, false, false, false, false, $usedSchemas);

        self::assertSame(['description' => 'Custom success'], $responses[200]);
    }

    #[Test]
    public function aDeclared404MergesWithTheGeneratorDerived404InsteadOfDuplicating(): void
    {
        $usedSchemas = [];
        $declared = [['status' => 404, 'schema' => null, 'collection' => false, 'description' => 'Nothing here']];

        // hasInput/authenticated/csrf false, but 404 is unconditional — proves it is not added twice.
        $responses = $this->builder()->build($declared, false, false, false, false, false, $usedSchemas);

        self::assertSame([200, 404], array_keys($responses));
        self::assertSame('Nothing here', $responses[404]['description']);
    }

    #[Test]
    public function aDeclaredSchemaProducesAJsonContentRef(): void
    {
        $usedSchemas = [];
        $declared = [['status' => 200, 'schema' => CourseDto::class, 'collection' => false, 'description' => null]];

        $responses = $this->builder()->build($declared, false, false, false, false, false, $usedSchemas);

        self::assertSame('#/components/schemas/CourseDto', $responses[200]['content']['application/json']['schema']['$ref']);
        self::assertArrayHasKey('CourseDto', $usedSchemas);
        self::assertSame(CourseDto::class, $usedSchemas['CourseDto']['class']);
        self::assertSame('object', $usedSchemas['CourseDto']['schema']['type']);
    }

    #[Test]
    public function aCollectionEntryWrapsTheReferencedSchemaInAnArray(): void
    {
        $usedSchemas = [];
        $declared = [['status' => 200, 'schema' => CourseDto::class, 'collection' => true, 'description' => null]];

        $responses = $this->builder()->build($declared, false, false, false, false, false, $usedSchemas);

        self::assertSame('array', $responses[200]['content']['application/json']['schema']['type']);
        self::assertSame('#/components/schemas/CourseDto', $responses[200]['content']['application/json']['schema']['items']['$ref']);
    }

    #[Test]
    public function aNullSchemaProducesNoContentKey(): void
    {
        $usedSchemas = [];
        $declared = [['status' => 204, 'schema' => null, 'collection' => false, 'description' => null]];

        $responses = $this->builder()->build($declared, false, false, false, false, false, $usedSchemas);

        self::assertArrayNotHasKey('content', $responses[204]);
        self::assertSame('No Content', $responses[204]['description']);
    }

    #[Test]
    public function reusesTheSameSchemaEntryForTheSameClassAcrossCalls(): void
    {
        $usedSchemas = [];
        $declared = [['status' => 200, 'schema' => CourseDto::class, 'collection' => false, 'description' => null]];

        $this->builder()->build($declared, false, false, false, false, false, $usedSchemas);
        $this->builder()->build($declared, false, false, false, false, false, $usedSchemas);

        self::assertCount(1, $usedSchemas);
    }

    #[Test]
    public function rejectsTwoDifferentClassesResolvingToTheSameShortName(): void
    {
        $usedSchemas = ['CourseDto' => ['class' => CollidingCourseDto::class, 'schema' => ['type' => 'object', 'properties' => []]]];
        $declared = [['status' => 200, 'schema' => CourseDto::class, 'collection' => false, 'description' => null]];

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000038);
        $this->expectExceptionMessageMatches('/both resolve to the OpenAPI schema name "CourseDto"/');

        $this->builder()->build($declared, false, false, false, false, false, $usedSchemas);
    }

    #[Test]
    public function fallsBackToAGenericDescriptionForAnUncommonStatus(): void
    {
        $usedSchemas = [];
        $declared = [['status' => 418, 'schema' => null, 'collection' => false, 'description' => null]];

        $responses = $this->builder()->build($declared, false, false, false, false, false, $usedSchemas);

        self::assertSame('Response', $responses[418]['description']);
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function commonStatuses(): iterable
    {
        yield '200' => [200, 'Successful response'];
        yield '201' => [201, 'Created'];
        yield '202' => [202, 'Accepted'];
        yield '204' => [204, 'No Content'];
        yield '400' => [400, 'Bad Request'];
        yield '401' => [401, 'Unauthorized'];
        yield '403' => [403, 'Forbidden'];
        yield '404' => [404, 'Not Found'];
        yield '405' => [405, 'Method Not Allowed'];
        yield '409' => [409, 'Conflict'];
        yield '422' => [422, 'Unprocessable Content'];
        yield '429' => [429, 'Too Many Requests'];
    }

    #[Test]
    #[DataProvider('commonStatuses')]
    public function usesASensibleDefaultDescriptionPerCommonStatus(int $status, string $expectedDescription): void
    {
        $usedSchemas = [];
        $declared = [['status' => $status, 'schema' => null, 'collection' => false, 'description' => null]];

        $responses = $this->builder()->build($declared, false, false, false, false, false, $usedSchemas);

        self::assertSame($expectedDescription, $responses[$status]['description']);
    }

    private function builder(): ResponsesBuilder
    {
        return new ResponsesBuilder(new JsonSchemaMapper());
    }
}
