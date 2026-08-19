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

use KonradMichalik\Typo3Routing\Authentication\BearerTokenAuthenticator;
use KonradMichalik\Typo3Routing\Controller\SwaggerUiController;
use KonradMichalik\Typo3Routing\OpenApi\{JsonSchemaMapper, OpenApiGenerator, ResponsesBuilder};
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Dto\Collision\CourseDto as CollidingCourseDto;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Dto\CourseDto;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Entity\Item;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Enum\{Priority, Status};
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * OpenApiGeneratorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(OpenApiGenerator::class)]
final class OpenApiGeneratorTest extends TestCase
{
    #[Test]
    public function emitsDocumentHeaderInfoAndServer(): void
    {
        $document = $this->generate();

        self::assertSame('3.1.0', $document['openapi']);
        self::assertSame(['title' => 'My API', 'version' => '2.0.0'], $document['info']);
        self::assertSame([['url' => '/api/']], $document['servers']);
    }

    #[Test]
    public function omitsServersWhenServerIsEmpty(): void
    {
        $document = $this->generator($this->registry())->generate('My API', '2.0.0', '');

        self::assertArrayNotHasKey('servers', $document);
    }

    #[Test]
    public function marksADeprecatedRouteAsDeprecatedInTheOperation(): void
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = ['x' => ['path' => '/api/x', 'methods' => ['GET'], 'controller' => 'ctrl::x', 'env' => null, 'requirements' => []]];
        $registry = new RouteRegistry($routes, new ServiceLocator([]), deprecations: ['x' => ['since' => 1, 'sunset' => null, 'successor' => null, 'documentation' => null]]);

        $document = $this->generator($registry)->generate('My API', '2.0.0', '/api/');

        self::assertTrue($document['paths']['/api/x']['get']['deprecated']);
    }

    #[Test]
    public function omitsTheDeprecatedFlagForARouteWithoutTheAttribute(): void
    {
        $document = $this->generate();

        self::assertArrayNotHasKey('deprecated', $document['paths']['/api/v1/items/{id}']['get']);
    }

    #[Test]
    public function mapsPathAndQueryParametersWithTypeAndEnumSchema(): void
    {
        $operation = $this->generate()['paths']['/api/v1/items/{id}']['get'];

        self::assertSame('items_show', $operation['operationId']);
        self::assertSame(['ctrl'], $operation['tags']);

        [$id, $status] = $operation['parameters'];
        self::assertSame(['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']], $id);
        self::assertSame('status', $status['name']);
        self::assertSame('query', $status['in']);
        self::assertFalse($status['required']);
        self::assertSame(['type' => 'string', 'enum' => ['active', 'inactive']], $status['schema']);
    }

    #[Test]
    public function usesTheRoutesOwnTagsInsteadOfTheServiceIdWhenSet(): void
    {
        $operation = $this->generate()['paths']['/api/v1/items-tagged']['get'];

        self::assertSame(['Items', 'Catalog'], $operation['tags']);
    }

    #[Test]
    public function addsParamDescriptionToTheParameterAndOmitsItWhenAbsent(): void
    {
        [$id, $status] = $this->generate()['paths']['/api/v1/items/{id}']['get']['parameters'];

        self::assertSame('Filter by publication status.', $status['description']);
        // A parameter without a #[Param] description stays free of the key entirely.
        self::assertArrayNotHasKey('description', $id);
    }

    #[Test]
    public function addsParamDescriptionToABodyProperty(): void
    {
        $schema = $this->generate()['paths']['/api/v1/items']['post']['requestBody']['content']['application/json']['schema'];

        self::assertSame('Human-readable item title.', $schema['properties']['title']['description']);
    }

    #[Test]
    public function buildsRequestBodyForBodyParametersOnUnsafeMethods(): void
    {
        $operation = $this->generate()['paths']['/api/v1/items']['post'];

        self::assertArrayNotHasKey('parameters', $operation);
        $schema = $operation['requestBody']['content']['application/json']['schema'];
        self::assertSame('object', $schema['type']);
        self::assertSame(['type' => 'string', 'description' => 'Human-readable item title.'], $schema['properties']['title']);
        self::assertSame(['title'], $schema['required']);
        self::assertTrue($operation['requestBody']['required']);
    }

    #[Test]
    public function attachesSecurityAndErrorResponsesFromRouteFeatures(): void
    {
        $document = $this->generate();
        $operation = $document['paths']['/api/v1/items']['post'];

        self::assertSame([['bearerAuth' => []]], $operation['security']);
        self::assertSame(['type' => 'http', 'scheme' => 'bearer'], $document['components']['securitySchemes']['bearerAuth']);

        // POST with body + auth + request token + rate limit exercises every generic error response.
        // Numeric string keys are normalised to ints by PHP; json_encode serialises them as "200" etc.
        self::assertSame(
            [200, 400, 401, 403, 404, 405, 429],
            array_keys($operation['responses']),
        );
        self::assertSame(
            '#/components/schemas/Error',
            $operation['responses'][400]['content']['application/problem+json']['schema']['$ref'],
        );
    }

    #[Test]
    public function definesTheSharedErrorSchema(): void
    {
        $schema = $this->generate()['components']['schemas']['Error'];

        self::assertSame('object', $schema['type']);
        self::assertSame(['type', 'title', 'status'], $schema['required']);
        self::assertSame('string', $schema['properties']['type']['type']);
        self::assertSame('string', $schema['properties']['title']['type']);
        self::assertSame('integer', $schema['properties']['status']['type']);
        self::assertSame('string', $schema['properties']['detail']['type']);
    }

    #[Test]
    public function mapsScalarVariadicAndUntypedQueryParameterSchemas(): void
    {
        $params = $this->schemasByName($this->features()['paths']['/api/types']['get']['parameters']);

        self::assertSame(['type' => 'number'], $params['f']);
        self::assertSame(['type' => 'boolean'], $params['b']);
        // items is an empty schema object ({} in JSON), so compare structurally.
        self::assertSame('array', $params['arr']['type']);
        self::assertArrayHasKey('items', $params['arr']);
        self::assertSame([], $params['m']);
        // Untyped input becomes a raw string, and a requirement regex becomes the pattern.
        self::assertSame(['type' => 'string', 'pattern' => '[a-z]+'], $params['raw']);
        // A variadic parameter is exposed as a query parameter.
        self::assertSame(['type' => 'integer'], $params['ids']);
    }

    #[Test]
    public function mapsIntBackedEnumToIntegerSchema(): void
    {
        $params = $this->schemasByName($this->features()['paths']['/api/types']['get']['parameters']);

        self::assertSame(['type' => 'integer', 'enum' => [1, 5]], $params['level']);
    }

    /**
     * A requirement on an enum-typed argument narrows the exported `enum` to what the route accepts —
     * without it the document would advertise values the router rejects with a 404.
     */
    #[Test]
    public function narrowsEnumParameterSchemaToTheCasesTheRequirementAccepts(): void
    {
        $params = $this->schemasByName($this->features()['paths']['/api/types']['get']['parameters']);

        self::assertSame(['type' => 'string', 'enum' => ['active']], $params['state']);
    }

    /**
     * An Extbase-entity-typed argument is resolved from a record UID, so the export describes the UID.
     */
    #[Test]
    public function mapsExtbaseDomainObjectToIntegerUidSchema(): void
    {
        $params = $this->schemasByName($this->features()['paths']['/api/types']['get']['parameters']);

        self::assertSame(['type' => 'integer'], $params['item']);
    }

    #[Test]
    public function buildsOptionalRequestBodyWithoutRequiredList(): void
    {
        $operation = $this->features()['paths']['/api/note']['post'];

        $schema = $operation['requestBody']['content']['application/json']['schema'];
        self::assertSame(['type' => 'string'], $schema['properties']['note']);
        self::assertArrayNotHasKey('required', $schema);
        self::assertFalse($operation['requestBody']['required']);
    }

    #[Test]
    public function mapsUnknownAuthenticatorToBearerSchemeNamedAfterClass(): void
    {
        $document = $this->features();
        $operation = $document['paths']['/api/custom']['get'];

        self::assertSame([['apiKeyAuthenticator' => []]], $operation['security']);
        self::assertSame(['type' => 'http', 'scheme' => 'bearer'], $document['components']['securitySchemes']['apiKeyAuthenticator']);
    }

    #[Test]
    public function mentionsTheApplicationContextForEnvBoundRoutes(): void
    {
        $operation = $this->features()['paths']['/api/dev']['get'];

        self::assertStringContainsString('Development', $operation['description']);
    }

    #[Test]
    public function excludesTheSwaggerUiControllersOwnRoutesFromTheDocument(): void
    {
        self::assertArrayNotHasKey('/api/_routing/openapi.json', $this->features()['paths']);
    }

    #[Test]
    public function usesRouteDescriptionAndSplitsOffTheFirstSentenceAsSummary(): void
    {
        $operation = $this->features()['paths']['/api/described']['get'];

        self::assertSame('Fetch a single course by its numeric ID.', $operation['summary']);
        self::assertSame('Fetch a single course by its numeric ID. Includes schedule and availability.', $operation['description']);
    }

    #[Test]
    public function omitsSummaryWhenTheRouteDescriptionIsASingleSentence(): void
    {
        $operation = $this->features()['paths']['/api/single-sentence']['get'];

        self::assertArrayNotHasKey('summary', $operation);
        self::assertSame('A lone sentence with no split point', $operation['description']);
    }

    #[Test]
    public function aDeclaredReturnsProducesAContentSchemaOnTheOperation(): void
    {
        $operation = $this->features()['paths']['/api/course']['get'];

        self::assertSame(
            '#/components/schemas/CourseDto',
            $operation['responses'][200]['content']['application/json']['schema']['$ref'],
        );
    }

    #[Test]
    public function aRepeatedReturnsMergesWithTheGeneratorDerivedStatusInsteadOfDuplicating(): void
    {
        $operation = $this->features()['paths']['/api/course']['get'];

        self::assertSame('Course not found', $operation['responses'][404]['description']);
        // The declared 404 has no schema, so it stays a plain description — no content key at all.
        self::assertArrayNotHasKey('content', $operation['responses'][404]);
        // 404 appears exactly once — no duplicate alongside the declared one; 405 is still added
        // separately since the route declares no #[Returns] for it.
        self::assertSame([200, 404, 405], array_keys($operation['responses']));
    }

    #[Test]
    public function aCollectionReturnsProducesAnArraySchemaOfTheReferencedType(): void
    {
        $operation = $this->features()['paths']['/api/courses']['get'];
        $schema = $operation['responses'][200]['content']['application/json']['schema'];

        self::assertSame('array', $schema['type']);
        self::assertSame('#/components/schemas/CourseDto', $schema['items']['$ref']);
    }

    #[Test]
    public function aNullSchemaReturnsDescribesAResponseWithNoContent(): void
    {
        $operation = $this->features()['paths']['/api/no-body']['get'];

        self::assertSame(['description' => 'No Content'], $operation['responses'][204]);
        self::assertArrayNotHasKey('content', $operation['responses'][204]);
    }

    #[Test]
    public function aRouteWithoutReturnsProducesExactlyTheGenericSuccessResponse(): void
    {
        $operation = $this->features()['paths']['/api/dev']['get'];

        self::assertSame(['description' => 'Successful response'], $operation['responses'][200]);
    }

    #[Test]
    public function sharesOneComponentsSchemaEntryAcrossRoutesDeclaringTheSameDto(): void
    {
        $document = $this->features();

        self::assertArrayHasKey('CourseDto', $document['components']['schemas']);
        self::assertSame('object', $document['components']['schemas']['CourseDto']['type']);
        self::assertSame(
            $document['paths']['/api/course']['get']['responses'][200]['content']['application/json']['schema'],
            $document['paths']['/api/course-again']['get']['responses'][200]['content']['application/json']['schema'],
        );
    }

    #[Test]
    public function rejectsTwoDifferentClassesResolvingToTheSameSchemaName(): void
    {
        $routes = [
            'a' => ['path' => '/api/a', 'methods' => ['GET'], 'controller' => 'ctrl::a', 'env' => null, 'requirements' => []],
            'b' => ['path' => '/api/b', 'methods' => ['GET'], 'controller' => 'ctrl::b', 'env' => null, 'requirements' => []],
        ];
        $returns = [
            'a' => [['status' => 200, 'schema' => CourseDto::class, 'collection' => false, 'description' => null]],
            'b' => [['status' => 200, 'schema' => CollidingCourseDto::class, 'collection' => false, 'description' => null]],
        ];
        $registry = new RouteRegistry($routes, new ServiceLocator([]), [], [], ['a' => [], 'b' => []], returns: $returns);

        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000038);
        $this->expectExceptionMessageMatches('/both resolve to the OpenAPI schema name "CourseDto"/');

        $this->generator($registry)->generate('My API', '1.0.0', '');
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(): array
    {
        return $this->generator($this->registry())->generate('My API', '2.0.0', '/api/');
    }

    /**
     * @return array<string, mixed>
     */
    private function features(): array
    {
        return $this->generator($this->featureRegistry())->generate('My API', '1.0.0', '/api/');
    }

    private function generator(RouteRegistry $registry): OpenApiGenerator
    {
        $schemas = new JsonSchemaMapper();

        return new OpenApiGenerator($registry, $schemas, new ResponsesBuilder($schemas));
    }

    /**
     * @param list<array{name: string, schema: array<string, mixed>}> $parameters
     *
     * @return array<string, array<string, mixed>>
     */
    private function schemasByName(array $parameters): array
    {
        $schemas = [];
        foreach ($parameters as $parameter) {
            $schemas[$parameter['name']] = $parameter['schema'];
        }

        return $schemas;
    }

    private function featureRegistry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, description?: string|null}> $routes */
        $routes = [
            'types' => ['path' => '/api/types', 'methods' => ['GET'], 'controller' => 'ctrl::types', 'env' => null, 'requirements' => ['raw' => '[a-z]+', 'state' => 'active']],
            'note' => ['path' => '/api/note', 'methods' => ['POST'], 'controller' => 'ctrl::note', 'env' => null, 'requirements' => []],
            'custom' => ['path' => '/api/custom', 'methods' => ['GET'], 'controller' => 'ctrl::custom', 'env' => null, 'requirements' => []],
            'dev' => ['path' => '/api/dev', 'methods' => ['GET'], 'controller' => 'ctrl::dev', 'env' => 'Development', 'requirements' => []],
            'routing_swagger_openapi_json' => ['path' => '/api/_routing/openapi.json', 'methods' => ['GET'], 'controller' => SwaggerUiController::class.'::openApiJson', 'env' => 'Development', 'requirements' => []],
            'described' => ['path' => '/api/described', 'methods' => ['GET'], 'controller' => 'ctrl::described', 'env' => null, 'requirements' => [], 'description' => 'Fetch a single course by its numeric ID. Includes schedule and availability.'],
            'singleSentence' => ['path' => '/api/single-sentence', 'methods' => ['GET'], 'controller' => 'ctrl::singleSentence', 'env' => null, 'requirements' => [], 'description' => 'A lone sentence with no split point'],
            'course' => ['path' => '/api/course', 'methods' => ['GET'], 'controller' => 'ctrl::course', 'env' => null, 'requirements' => []],
            'courseAgain' => ['path' => '/api/course-again', 'methods' => ['GET'], 'controller' => 'ctrl::courseAgain', 'env' => null, 'requirements' => []],
            'courses' => ['path' => '/api/courses', 'methods' => ['GET'], 'controller' => 'ctrl::courses', 'env' => null, 'requirements' => []],
            'noBody' => ['path' => '/api/no-body', 'methods' => ['GET'], 'controller' => 'ctrl::noBody', 'env' => null, 'requirements' => []],
        ];

        $arguments = [
            'types' => [
                ['name' => 'f', 'type' => 'float', 'source' => 'query', 'nullable' => true, 'hasDefault' => false, 'default' => null],
                ['name' => 'b', 'type' => 'bool', 'source' => 'query', 'nullable' => true, 'hasDefault' => false, 'default' => null],
                ['name' => 'arr', 'type' => 'array', 'source' => 'query', 'nullable' => true, 'hasDefault' => false, 'default' => null],
                ['name' => 'm', 'type' => 'mixed', 'source' => 'query', 'nullable' => true, 'hasDefault' => false, 'default' => null],
                ['name' => 'raw', 'type' => null, 'source' => 'input', 'nullable' => true, 'hasDefault' => false, 'default' => null],
                ['name' => 'ids', 'type' => 'int', 'source' => 'variadic', 'nullable' => false, 'hasDefault' => false, 'default' => null],
                ['name' => 'level', 'type' => Priority::class, 'source' => 'query', 'nullable' => true, 'hasDefault' => false, 'default' => null],
                ['name' => 'item', 'type' => Item::class, 'source' => 'query', 'nullable' => true, 'hasDefault' => false, 'default' => null],
                ['name' => 'state', 'type' => Status::class, 'source' => 'query', 'nullable' => true, 'hasDefault' => false, 'default' => null],
            ],
            'note' => [
                // Optional body parameter → not in the required list.
                ['name' => 'note', 'type' => 'string', 'source' => 'body', 'nullable' => true, 'hasDefault' => false, 'default' => null],
            ],
            'custom' => [],
            'dev' => [],
            'routing_swagger_openapi_json' => [],
            'described' => [],
            'singleSentence' => [],
            'course' => [],
            'courseAgain' => [],
            'courses' => [],
            'noBody' => [],
        ];

        $returns = [
            'course' => [
                ['status' => 200, 'schema' => CourseDto::class, 'collection' => false, 'description' => null],
                ['status' => 404, 'schema' => null, 'collection' => false, 'description' => 'Course not found'],
            ],
            'courseAgain' => [
                ['status' => 200, 'schema' => CourseDto::class, 'collection' => false, 'description' => null],
            ],
            'courses' => [
                ['status' => 200, 'schema' => CourseDto::class, 'collection' => true, 'description' => null],
            ],
            'noBody' => [
                ['status' => 204, 'schema' => null, 'collection' => false, 'description' => null],
            ],
        ];

        return new RouteRegistry(
            $routes,
            new ServiceLocator([]),
            [],
            [],
            $arguments,
            ['custom' => [['service' => 'App\\Security\\ApiKeyAuthenticator', 'options' => []]]],
            returns: $returns,
        );
    }

    private function registry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, tags?: list<string>}> $routes */
        $routes = [
            'items_show' => [
                'path' => '/api/v1/items/{id}',
                'methods' => ['GET'],
                'controller' => 'ctrl::show',
                'env' => null,
                'requirements' => ['id' => '\d+'],
            ],
            'items_create' => [
                'path' => '/api/v1/items',
                'methods' => ['POST'],
                'controller' => 'ctrl::create',
                'env' => null,
                'requirements' => [],
            ],
            'items_tagged' => [
                'path' => '/api/v1/items-tagged',
                'methods' => ['GET'],
                'controller' => 'ctrl::tagged',
                'env' => null,
                'requirements' => [],
                'tags' => ['Items', 'Catalog'],
            ],
        ];

        $arguments = [
            'items_show' => [
                ['name' => 'id', 'type' => 'int', 'source' => 'path', 'nullable' => false, 'hasDefault' => false, 'default' => null],
                ['name' => 'status', 'type' => Status::class, 'source' => 'query', 'nullable' => true, 'hasDefault' => false, 'default' => null],
            ],
            'items_create' => [
                ['name' => 'title', 'type' => 'string', 'source' => 'input', 'nullable' => false, 'hasDefault' => false, 'default' => null],
                ['name' => 'request', 'type' => null, 'source' => 'request', 'nullable' => false, 'hasDefault' => false, 'default' => null],
            ],
        ];

        return new RouteRegistry(
            $routes,
            new ServiceLocator([]),
            [],
            ['items_create' => ['limit' => 60, 'interval' => '1 minute', 'policy' => 'sliding_window', 'keyBy' => 'ip']],
            $arguments,
            ['items_create' => [['service' => BearerTokenAuthenticator::class, 'options' => []]]],
            ['items_create' => 'routing/items_create'],
            paramDescriptions: [
                'items_show' => ['status' => 'Filter by publication status.'],
                'items_create' => ['title' => 'Human-readable item title.'],
            ],
        );
    }
}
