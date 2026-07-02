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

use KonradMichalik\Typo3Routing\Authentication\{BackendUserAuthenticator, BearerTokenAuthenticator, FrontendUserAuthenticator};
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use UnitEnum;

use function array_key_exists;
use function explode;
use function in_array;
use function is_a;
use function lcfirst;
use function sprintf;
use function str_contains;
use function strrpos;
use function strtolower;
use function strtoupper;
use function substr;

/**
 * OpenApiGenerator.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class OpenApiGenerator
{
    /**
     * Known authenticator classes mapped to a reusable OpenAPI security scheme.
     *
     * @var array<class-string, array{name: string, scheme: array<string, string>}>
     */
    private const SECURITY_SCHEMES = [
        BearerTokenAuthenticator::class => ['name' => 'bearerAuth', 'scheme' => ['type' => 'http', 'scheme' => 'bearer']],
        FrontendUserAuthenticator::class => ['name' => 'frontendUser', 'scheme' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'fe_typo_user']],
        BackendUserAuthenticator::class => ['name' => 'backendUser', 'scheme' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'be_typo_user']],
    ];

    /**
     * @var list<string>
     */
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    public function __construct(
        private RouteRegistry $registry,
    ) {}

    /**
     * @return array<string, mixed> the OpenAPI 3.1 document
     */
    public function generate(string $title, string $version, string $server): array
    {
        $paths = [];
        $usedSchemes = [];

        foreach ($this->registry->getRoutes() as $name => $route) {
            $methods = [] === $route['methods'] ? ['GET'] : $route['methods'];
            foreach ($methods as $method) {
                $operation = $this->operation($name, $route, $method, $usedSchemes);
                $paths[$route['path']][strtolower($method)] = $operation;
            }
        }

        $document = [
            'openapi' => '3.1.0',
            'info' => ['title' => $title, 'version' => $version],
        ];

        if ('' !== $server) {
            $document['servers'] = [['url' => $server]];
        }

        $document['paths'] = $paths;
        $document['components'] = [
            'schemas' => ['Error' => $this->errorSchema()],
            'securitySchemes' => $usedSchemes,
        ];

        return $document;
    }

    /**
     * @param array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>} $route
     * @param array<string, array<string, string>>                                                                                  $usedSchemes
     *
     * @return array<string, mixed>
     */
    private function operation(string $name, array $route, string $method, array &$usedSchemes): array
    {
        [$serviceId] = explode('::', $route['controller'], 2);
        $arguments = $this->registry->getArguments($name);
        $hasBody = in_array(strtoupper($method), self::BODY_METHODS, true);

        $parameters = [];
        $bodyProperties = [];
        $bodyRequired = [];

        foreach ($arguments as $argument) {
            $pattern = $route['requirements'][$argument['name']] ?? null;
            $schema = $this->schemaForType($argument['type'], '' === $pattern ? null : $pattern);

            match ($this->target($argument['source'], $hasBody)) {
                'path' => $parameters[] = $this->parameter($argument['name'], 'path', true, $schema),
                'query' => $parameters[] = $this->parameter($argument['name'], 'query', !$argument['nullable'] && !$argument['hasDefault'], $schema),
                'body' => $this->collectBody($argument, $schema, $bodyProperties, $bodyRequired),
                default => null, // 'request' — the PSR-7 request is not an API parameter.
            };
        }

        $operation = [
            'operationId' => $name,
            'tags' => [$serviceId],
            'description' => $this->description($name, $route),
        ];

        if ([] !== $parameters) {
            $operation['parameters'] = $parameters;
        }

        if ([] !== $bodyProperties) {
            $operation['requestBody'] = $this->requestBody($bodyProperties, $bodyRequired);
        }

        $security = $this->security($name, $usedSchemes);
        if ([] !== $security) {
            $operation['security'] = $security;
        }

        $operation['responses'] = $this->responses($name, $route, [] !== $parameters || [] !== $bodyProperties);

        return $operation;
    }

    /**
     * Maps the argument spec source to where it appears in OpenAPI. The catch-all "input" source
     * (query + body) becomes a request-body property for methods that carry a body, else a query
     * parameter.
     */
    private function target(string $source, bool $hasBody): string
    {
        return match ($source) {
            'path' => 'path',
            'query' => 'query',
            'body' => 'body',
            'variadic' => 'query',
            'input' => $hasBody ? 'body' : 'query',
            default => 'request',
        };
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function parameter(string $name, string $in, bool $required, array $schema): array
    {
        return ['name' => $name, 'in' => $in, 'required' => $required, 'schema' => $schema];
    }

    /**
     * @param array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed} $argument
     * @param array<string, mixed>                                                                                     $schema
     * @param array<string, array<string, mixed>>                                                                      $properties
     * @param list<string>                                                                                             $required
     */
    private function collectBody(array $argument, array $schema, array &$properties, array &$required): void
    {
        $properties[$argument['name']] = $schema;
        if (!$argument['nullable'] && !$argument['hasDefault']) {
            $required[] = $argument['name'];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string>                        $required
     *
     * @return array<string, mixed>
     */
    private function requestBody(array $properties, array $required): array
    {
        $schema = ['type' => 'object', 'properties' => $properties];
        if ([] !== $required) {
            $schema['required'] = $required;
        }

        return [
            'required' => [] !== $required,
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaForType(?string $type, ?string $pattern): array
    {
        if (null !== $type && is_a($type, UnitEnum::class, true)) {
            return $this->enumSchema($type);
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
     * @param class-string<UnitEnum> $enum
     *
     * @return array<string, mixed>
     */
    private function enumSchema(string $enum): array
    {
        $reflection = new ReflectionEnum($enum);
        $backingType = $reflection->getBackingType()?->getName();

        $values = [];
        foreach ($reflection->getCases() as $case) {
            // A backed enum's cases are always backed; the guard keeps the type checker happy.
            if ($case instanceof ReflectionEnumBackedCase) {
                $values[] = $case->getBackingValue();
            }
        }

        return [
            'type' => 'int' === $backingType ? 'integer' : 'string',
            'enum' => $values,
        ];
    }

    /**
     * OR-combined authenticators become a list of security requirements (any one satisfies the route).
     *
     * @param array<string, array<string, string>> $usedSchemes
     *
     * @return list<array<string, list<string>>>
     */
    private function security(string $name, array &$usedSchemes): array
    {
        $security = [];
        foreach ($this->registry->getAuthenticators($name) as $authenticator) {
            $scheme = $this->schemeFor($authenticator['service']);
            $usedSchemes[$scheme['name']] = $scheme['definition'];
            $security[] = [$scheme['name'] => []];
        }

        return $security;
    }

    /**
     * @return array{name: string, definition: array<string, string>}
     */
    private function schemeFor(string $service): array
    {
        if (array_key_exists($service, self::SECURITY_SCHEMES)) {
            $known = self::SECURITY_SCHEMES[$service];

            return ['name' => $known['name'], 'definition' => $known['scheme']];
        }

        // Unknown custom authenticators default to HTTP bearer, named after the class short name.
        $shortName = str_contains($service, '\\') ? substr($service, (int) strrpos($service, '\\') + 1) : $service;

        return ['name' => lcfirst($shortName), 'definition' => ['type' => 'http', 'scheme' => 'bearer']];
    }

    /**
     * @param array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>} $route
     */
    private function description(string $name, array $route): string
    {
        $description = sprintf('Handled by %s.', $route['controller']);

        if (null !== $this->registry->getRequestTokenScope($name)) {
            $description .= sprintf(' Requires a valid request token (scope "%s") for unsafe methods.', $this->registry->getRequestTokenScope($name));
        }

        if (null !== $route['env']) {
            $description .= sprintf(' Only available in the "%s" application context.', $route['env']);
        }

        return $description;
    }

    /**
     * @param array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>} $route
     *
     * @return array<int, array<string, mixed>>
     */
    private function responses(string $name, array $route, bool $hasInput): array
    {
        $responses = ['200' => ['description' => 'Successful response']];

        if ($hasInput) {
            $responses['400'] = $this->errorResponse('Bad Request (missing or invalid parameter)');
        }
        if ([] !== $this->registry->getAuthenticators($name)) {
            $responses['401'] = $this->errorResponse('Unauthorized');
        }
        if (null !== $this->registry->getRequestTokenScope($name)) {
            $responses['403'] = $this->errorResponse('Forbidden (invalid request token)');
        }

        $responses['404'] = $this->errorResponse('Not Found');

        if ([] !== $route['methods']) {
            $responses['405'] = $this->errorResponse('Method Not Allowed');
        }
        if (null !== $this->registry->getRateLimit($name)) {
            $responses['429'] = $this->errorResponse('Too Many Requests');
        }

        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
        ];
    }

    /**
     * Matches the body emitted by JsonErrorResponse.
     *
     * @return array<string, mixed>
     */
    private function errorSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'error' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
            ],
            'required' => ['error', 'status'],
        ];
    }
}
