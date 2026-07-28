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

use ReflectionEnum;
use ReflectionEnumBackedCase;
use UnitEnum;

use function is_a;

/**
 * JsonSchemaMapper.
 *
 * @api
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class JsonSchemaMapper
{
    /**
     * Turns an argument's `type` (as `RouteRegistry::getArguments()` reports it) into a JSON Schema
     * fragment. docs/EXTENDING.md documents the resulting schema per type, and the BC promise.
     *
     * @param string|null $type    a scalar name (`int`, `float`, `bool`, `array`, `mixed`, `string`), a
     *                             `class-string<UnitEnum>` of a **backed** enum, or `null` for untyped
     * @param string|null $pattern the route's requirement for this argument, applied only to a schema
     *                             that ends up `{"type": "string"}`
     *
     * @return array<string, mixed>
     */
    public function schemaForType(?string $type, ?string $pattern = null): array
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
            // A pure enum cannot reach this mapper through the extension's own compile step, which
            // rejects one outright; an external caller passing one gets an empty `enum` list.
            if ($case instanceof ReflectionEnumBackedCase) {
                $values[] = $case->getBackingValue();
            }
        }

        return [
            'type' => 'int' === $backingType ? 'integer' : 'string',
            'enum' => $values,
        ];
    }
}
