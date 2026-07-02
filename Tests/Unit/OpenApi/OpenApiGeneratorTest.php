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
use KonradMichalik\Typo3Routing\OpenApi\OpenApiGenerator;
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Enum\{Priority, Status};
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
        $document = (new OpenApiGenerator($this->registry()))->generate('My API', '2.0.0', '');

        self::assertArrayNotHasKey('servers', $document);
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
    public function buildsRequestBodyForBodyParametersOnUnsafeMethods(): void
    {
        $operation = $this->generate()['paths']['/api/v1/items']['post'];

        self::assertArrayNotHasKey('parameters', $operation);
        $schema = $operation['requestBody']['content']['application/json']['schema'];
        self::assertSame('object', $schema['type']);
        self::assertSame(['type' => 'string'], $schema['properties']['title']);
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

    /**
     * @return array<string, mixed>
     */
    private function generate(): array
    {
        return (new OpenApiGenerator($this->registry()))->generate('My API', '2.0.0', '/api/');
    }

    /**
     * @return array<string, mixed>
     */
    private function features(): array
    {
        return (new OpenApiGenerator($this->featureRegistry()))->generate('My API', '1.0.0', '/api/');
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
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
        $routes = [
            'types' => ['path' => '/api/types', 'methods' => ['GET'], 'controller' => 'ctrl::types', 'env' => null, 'requirements' => ['raw' => '[a-z]+']],
            'note' => ['path' => '/api/note', 'methods' => ['POST'], 'controller' => 'ctrl::note', 'env' => null, 'requirements' => []],
            'custom' => ['path' => '/api/custom', 'methods' => ['GET'], 'controller' => 'ctrl::custom', 'env' => null, 'requirements' => []],
            'dev' => ['path' => '/api/dev', 'methods' => ['GET'], 'controller' => 'ctrl::dev', 'env' => 'Development', 'requirements' => []],
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
            ],
            'note' => [
                // Optional body parameter → not in the required list.
                ['name' => 'note', 'type' => 'string', 'source' => 'body', 'nullable' => true, 'hasDefault' => false, 'default' => null],
            ],
            'custom' => [],
            'dev' => [],
        ];

        return new RouteRegistry(
            $routes,
            new ServiceLocator([]),
            [],
            [],
            $arguments,
            ['custom' => [['service' => 'App\\Security\\ApiKeyAuthenticator', 'options' => []]]],
        );
    }

    private function registry(): RouteRegistry
    {
        /** @var array<string, array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>}> $routes */
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
            ['items_create' => ['limit' => 60, 'interval' => '1 minute', 'policy' => 'sliding_window']],
            $arguments,
            ['items_create' => [['service' => BearerTokenAuthenticator::class, 'options' => []]]],
            ['items_create' => 'routing/items_create'],
        );
    }
}
