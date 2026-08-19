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

use LogicException;

use function sprintf;
use function str_contains;
use function strrpos;
use function substr;

/**
 * ResponsesBuilder.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ResponsesBuilder
{
    public function __construct(
        private JsonSchemaMapper $schemas,
    ) {}

    /**
     * @param list<array{status: int, schema: class-string|null, collection: bool, description: string|null}> $declared
     * @param array<string, array{class: class-string, schema: array<string, mixed>}>                         $usedSchemas
     *
     * @return array<int, array<string, mixed>>
     */
    public function build(array $declared, bool $hasInput, bool $authenticated, bool $csrfProtected, bool $hasMethods, bool $rateLimited, array &$usedSchemas): array
    {
        $responses = ['200' => ['description' => 'Successful response']];

        // A declared #[Returns] overrides the generic entry at that status (200 above, or one of the
        // generator-derived ones below) rather than living alongside a duplicate.
        foreach ($declared as $entry) {
            $responses[$entry['status']] = $this->declaredResponse($entry, $usedSchemas);
        }

        $this->addIfMissing($responses, 400, $hasInput, 'Bad Request (missing or invalid parameter)');
        $this->addIfMissing($responses, 401, $authenticated, 'Unauthorized');
        $this->addIfMissing($responses, 403, $csrfProtected, 'Forbidden (invalid request token)');
        $this->addIfMissing($responses, 404, true, 'Not Found');
        $this->addIfMissing($responses, 405, $hasMethods, 'Method Not Allowed');
        $this->addIfMissing($responses, 429, $rateLimited, 'Too Many Requests');

        return $responses;
    }

    /**
     * @param array<int, array<string, mixed>> $responses
     */
    private function addIfMissing(array &$responses, int $status, bool $condition, string $description): void
    {
        if ($condition && !isset($responses[$status])) {
            $responses[$status] = $this->errorResponse($description);
        }
    }

    /**
     * @param array{status: int, schema: class-string|null, collection: bool, description: string|null} $entry
     * @param array<string, array{class: class-string, schema: array<string, mixed>}>                   $usedSchemas
     *
     * @return array<string, mixed>
     */
    private function declaredResponse(array $entry, array &$usedSchemas): array
    {
        $response = ['description' => $entry['description'] ?? $this->defaultStatusDescription($entry['status'])];

        if (null === $entry['schema']) {
            return $response;
        }

        $schema = $this->schemaRef($entry['schema'], $usedSchemas);
        if ($entry['collection']) {
            $schema = ['type' => 'array', 'items' => $schema];
        }
        $response['content'] = ['application/json' => ['schema' => $schema]];

        return $response;
    }

    private function defaultStatusDescription(int $status): string
    {
        return match ($status) {
            200 => 'Successful response',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            default => 'Response',
        };
    }

    /**
     * A `#[Returns]` schema is named after the class's short name and shared across every route
     * declaring it, so the same DTO never produces two separate `components/schemas` entries. Two
     * different classes sharing a short name is an ambiguity the build rejects rather than silently
     * letting the second one win.
     *
     * @param class-string                                                            $class
     * @param array<string, array{class: class-string, schema: array<string, mixed>}> $usedSchemas
     *
     * @return array<string, mixed>
     */
    private function schemaRef(string $class, array &$usedSchemas): array
    {
        $shortName = str_contains($class, '\\') ? substr($class, (int) strrpos($class, '\\') + 1) : $class;

        if (isset($usedSchemas[$shortName]) && $usedSchemas[$shortName]['class'] !== $class) {
            throw new LogicException(sprintf('#[Returns] schemas "%s" and "%s" both resolve to the OpenAPI schema name "%s". Rename one of the classes so their short names differ.', $usedSchemas[$shortName]['class'], $class, $shortName), 1750000038);
        }

        $usedSchemas[$shortName] ??= ['class' => $class, 'schema' => $this->schemas->objectSchemaForClass($class)];

        return ['$ref' => '#/components/schemas/'.$shortName];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
        ];
    }
}
