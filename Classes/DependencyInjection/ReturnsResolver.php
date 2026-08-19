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

namespace KonradMichalik\Typo3Routing\DependencyInjection;

use KonradMichalik\Typo3Routing\Attribute\Returns;
use LogicException;
use ReflectionMethod;

use function sprintf;

/**
 * ReturnsResolver.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ReturnsResolver
{
    /**
     * Stores the method's #[Returns] declarations on $collected, if any.
     */
    public function apply(ReflectionMethod $method, string $serviceId, string $name, CollectedRoutes $collected): void
    {
        $declared = $this->resolve($method, $serviceId, $name);
        if ([] !== $declared) {
            $collected->returns[$name] = $declared;
        }
    }

    /**
     * @return list<array{status: int, schema: class-string|null, collection: bool, description: string|null}>
     */
    private function resolve(ReflectionMethod $method, string $serviceId, string $name): array
    {
        $declared = [];
        $seenStatuses = [];
        foreach ($method->getAttributes(Returns::class) as $attribute) {
            $returns = $attribute->newInstance();

            if (isset($seenStatuses[$returns->status])) {
                throw new LogicException(sprintf('Route "%s" (%s::%s()) declares #[Returns] for status %d more than once. Each status may be declared at most once per route.', $name, $serviceId, $method->getName(), $returns->status), 1750000037);
            }
            $seenStatuses[$returns->status] = true;

            $declared[] = [
                'status' => $returns->status,
                'schema' => $returns->schema,
                'collection' => $returns->collection,
                'description' => $returns->description,
            ];
        }

        return $declared;
    }
}
