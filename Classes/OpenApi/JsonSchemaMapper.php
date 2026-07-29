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
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;
use UnitEnum;

use function is_a;
use function preg_match;

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
     *                             `class-string<UnitEnum>` of a **backed** enum, a
     *                             `class-string<DomainObjectInterface>`, or `null` for untyped
     * @param string|null $pattern the route's requirement for this argument: applied as `pattern` to a
     *                             schema that ends up `{"type": "string"}`, and used to narrow a
     *                             backed enum's `enum` list to the cases the route actually accepts
     *
     * @return array<string, mixed>
     */
    public function schemaForType(?string $type, ?string $pattern = null): array
    {
        if (null !== $type && is_a($type, UnitEnum::class, true)) {
            return $this->enumSchema($type, $pattern);
        }

        if (null !== $type && is_a($type, DomainObjectInterface::class, true)) {
            return ['type' => 'integer'];
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
    private function enumSchema(string $enum, ?string $pattern): array
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
            'enum' => $this->casesMatchingRequirement($values, $pattern),
        ];
    }

    /**
     * Drops the cases a non-empty route requirement rejects, so the `enum` list states what the route
     * accepts rather than what the PHP type allows. Matching mirrors how the requirement is enforced
     * for real — anchored and grouped, exactly as ControllerInvoker and Symfony's matcher apply it.
     *
     * @param list<int|string> $values
     *
     * @return list<int|string>
     */
    private function casesMatchingRequirement(array $values, ?string $pattern): array
    {
        // '' is "presence only" rather than a regex (see #[Route]), so it narrows nothing.
        if (null === $pattern || '' === $pattern) {
            return $values;
        }

        $regex = '#^(?:'.$pattern.')$#';
        if (false === @preg_match($regex, '')) {
            // An unusable requirement cannot be evaluated; report the enum unnarrowed rather than
            // claim no case is acceptable. Such a route never matches anyway — Symfony compiles the
            // broken regex and lets preg_match fail at match time.
            return $values;
        }

        $matching = [];
        foreach ($values as $value) {
            if (1 === preg_match($regex, (string) $value)) {
                $matching[] = $value;
            }
        }

        return $matching;
    }
}
