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
use KonradMichalik\Typo3Routing\Controller\SwaggerUiController;
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;

use function array_key_exists;
use function explode;
use function in_array;
use function lcfirst;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strpos;
use function strrpos;
use function strtolower;
use function strtoupper;
use function substr;

/**
 * OpenApiGenerator.
 *
 * @internal
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
        private JsonSchemaMapper $schemas,
        private ResponsesBuilder $responsesBuilder,
    ) {}

    /**
     * @return array<string, mixed> the OpenAPI 3.1 document
     */
    public function generate(string $title, string $version, string $server): array
    {
        $paths = [];
        $usedSchemes = [];
        $usedSchemas = [];

        foreach ($this->registry->getRoutes() as $name => $route) {
            if (str_starts_with($route['controller'], SwaggerUiController::class.'::')) {
                // The Swagger UI's own routes describe tooling, not the API surface — never in the document.
                continue;
            }

            $methods = [] === $route['methods'] ? ['GET'] : $route['methods'];
            foreach ($methods as $method) {
                $operation = $this->operation($name, $route, $method, $usedSchemes, $usedSchemas);
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
            'schemas' => ['Error' => $this->errorSchema(), ...$this->schemaDefinitions($usedSchemas)],
            'securitySchemes' => $usedSchemes,
        ];

        return $document;
    }

    /**
     * @param array<string, array{class: class-string, schema: array<string, mixed>}> $usedSchemas
     *
     * @return array<string, array<string, mixed>>
     */
    private function schemaDefinitions(array $usedSchemas): array
    {
        $definitions = [];
        foreach ($usedSchemas as $shortName => $entry) {
            $definitions[$shortName] = $entry['schema'];
        }

        return $definitions;
    }

    /**
     * @param array{path: string, methods: list<string>, controller: string, env: string|null, requirements: array<string, string>, description?: string|null, tags?: list<string>} $route
     * @param array<string, array<string, string>>                                                                                                                                  $usedSchemes
     * @param array<string, array{class: class-string, schema: array<string, mixed>}>                                                                                               $usedSchemas
     *
     * @return array<string, mixed>
     */
    private function operation(string $name, array $route, string $method, array &$usedSchemes, array &$usedSchemas): array
    {
        [$serviceId] = explode('::', $route['controller'], 2);
        $arguments = $this->registry->getArguments($name);
        $hasBody = in_array(strtoupper($method), self::BODY_METHODS, true);

        $parameters = [];
        $bodyProperties = [];
        $bodyRequired = [];

        $descriptions = $this->registry->getParamDescriptions($name);

        foreach ($arguments as $argument) {
            $pattern = $route['requirements'][$argument['name']] ?? null;
            $schema = $this->schemas->schemaForType($argument['type'], '' === $pattern ? null : $pattern);
            $description = $descriptions[$argument['name']] ?? null;

            match ($this->target($argument['source'], $hasBody)) {
                'path' => $parameters[] = $this->parameter($argument['name'], 'path', true, $schema, $description),
                'query' => $parameters[] = $this->parameter($argument['name'], 'query', !$argument['nullable'] && !$argument['hasDefault'], $schema, $description),
                'body' => $this->collectBody($argument, $schema, $bodyProperties, $bodyRequired, $description),
                // 'request' and 'host': neither the PSR-7 request nor a host placeholder is an API parameter.
                default => null,
            };
        }

        $routeDescription = $route['description'] ?? null;

        $operation = [
            'operationId' => $name,
            'tags' => $this->tags($route, $serviceId),
        ];

        if (null !== $routeDescription) {
            $summary = $this->summary($routeDescription);
            if (null !== $summary) {
                $operation['summary'] = $summary;
            }
        }

        $operation['description'] = $routeDescription ?? $this->description($name, $route);
        $operation = $this->withDeprecatedFlag($operation, $name);

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

        $operation['responses'] = $this->responses($name, $route, [] !== $parameters || [] !== $bodyProperties, $usedSchemas);

        return $operation;
    }

    /**
     * The route's own #[Route(tags:)], or the controller's service id when none was set — today's
     * unchanged default.
     *
     * @param array{tags?: list<string>} $route
     *
     * @return list<string>
     */
    private function tags(array $route, string $serviceId): array
    {
        $tags = $route['tags'] ?? [];

        return [] === $tags ? [$serviceId] : $tags;
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private function withDeprecatedFlag(array $operation, string $name): array
    {
        if (null !== $this->registry->getDeprecation($name)) {
            $operation['deprecated'] = true;
        }

        return $operation;
    }

    /**
     * Maps the argument spec source to where it appears in OpenAPI. The catch-all "input" source
     * (query + body) becomes a request-body property for methods that carry a body, else a query
     * parameter. A "host" placeholder has no OpenAPI counterpart — it is not part of the path
     * template — so it maps to itself and is dropped by the caller.
     */
    private function target(string $source, bool $hasBody): string
    {
        return match ($source) {
            'path' => 'path',
            'host' => 'host',
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
    private function parameter(string $name, string $in, bool $required, array $schema, ?string $description): array
    {
        $parameter = ['name' => $name, 'in' => $in, 'required' => $required, 'schema' => $schema];
        if (null !== $description) {
            $parameter['description'] = $description;
        }

        return $parameter;
    }

    /**
     * @param array{name: string, type: string|null, source: string, nullable: bool, hasDefault: bool, default: mixed} $argument
     * @param array<string, mixed>                                                                                     $schema
     * @param array<string, array<string, mixed>>                                                                      $properties
     * @param list<string>                                                                                             $required
     */
    private function collectBody(array $argument, array $schema, array &$properties, array &$required, ?string $description): void
    {
        if (null !== $description) {
            $schema['description'] = $description;
        }

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
     * Splits off the first sentence of a user-authored description to use as the OpenAPI `summary`,
     * mirroring how PHPDoc summaries relate to their full description. Not "trivially splittable"
     * (no ". " found) means the whole text is a single sentence, so no separate summary is emitted.
     */
    private function summary(string $description): ?string
    {
        $pos = strpos($description, '. ');

        return false === $pos ? null : substr($description, 0, $pos + 1);
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
     * @param array<string, array{class: class-string, schema: array<string, mixed>}>                                               $usedSchemas
     *
     * @return array<int, array<string, mixed>>
     */
    private function responses(string $name, array $route, bool $hasInput, array &$usedSchemas): array
    {
        return $this->responsesBuilder->build(
            $this->registry->getReturns($name),
            $hasInput,
            [] !== $this->registry->getAuthenticators($name),
            null !== $this->registry->getRequestTokenScope($name),
            [] !== $route['methods'],
            null !== $this->registry->getRateLimit($name),
            $usedSchemas,
        );
    }

    /**
     * Matches the RFC 9457 problem+json body emitted by JsonErrorResponse.
     *
     * @return array<string, mixed>
     */
    private function errorSchema(): array
    {
        $properties = [
            'type' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'status' => ['type' => 'integer'],
            'detail' => ['type' => 'string'],
        ];

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => ['type', 'title', 'status'],
        ];
    }
}
