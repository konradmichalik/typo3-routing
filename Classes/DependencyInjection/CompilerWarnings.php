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

use KonradMichalik\Typo3Routing\Attribute\Route;
use ReflectionMethod;

use function array_map;
use function assert;
use function get_object_vars;
use function implode;
use function sprintf;
use function trigger_error;

use const E_USER_WARNING;

/**
 * CompilerWarnings.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class CompilerWarnings
{
    public function warnIfControllerHasNoRoute(bool $hasRoute, string $serviceId, string $markerInterface): void
    {
        if ($hasRoute) {
            return;
        }

        trigger_error(sprintf('"%s" implements %s but declares no #[Route] on any method; it will never be reachable. Add a #[Route], or remove the marker interface if the controller is unused.', $serviceId, $markerInterface), E_USER_WARNING);
    }

    /**
     * The same-named method one level up, if it declares a #[Route] and this one overrides it.
     */
    public function findOverriddenRouteMethod(ReflectionMethod $method, string $routeAttribute): ?ReflectionMethod
    {
        $parentClass = $method->getDeclaringClass()->getParentClass();
        if (false === $parentClass || !$parentClass->hasMethod($method->getName())) {
            return null;
        }

        $parentMethod = $parentClass->getMethod($method->getName());

        return [] === $parentMethod->getAttributes($routeAttribute) ? null : $parentMethod;
    }

    /**
     * #[Route] is repeatable (aliases on one method), so "the override still has #[Route]" is not
     * enough: an override repeating only some of the parent's #[Route] instances silently drops the
     * rest, exactly as an override with none at all drops the single one.
     */
    public function warnIfRouteWasDropped(?ReflectionMethod $overriddenRouteMethod, ReflectionMethod $method, string $serviceId): void
    {
        if (!$overriddenRouteMethod instanceof ReflectionMethod) {
            return;
        }

        $dropped = $this->droppedInstances($overriddenRouteMethod, $method, Route::class);
        if ([] === $dropped) {
            return;
        }

        $labels = array_map($this->routeLabel(...), $dropped);

        trigger_error(sprintf('"%s::%s()" overrides "%s::%s()", but does not repeat all its #[Route] attributes; the following inherited route(s) no longer exist: %s. Repeat every #[Route] (and any modifier attributes) on the override, or remove the override if no change was intended.', $serviceId, $method->getName(), $overriddenRouteMethod->getDeclaringClass()->getName(), $method->getName(), implode(', ', $labels)), E_USER_WARNING);
    }

    /**
     * @param array<class-string, string> $modifierAttributes
     */
    public function warnIfAModifierWasDropped(?ReflectionMethod $overriddenRouteMethod, ReflectionMethod $method, string $serviceId, array $modifierAttributes): void
    {
        if (!$overriddenRouteMethod instanceof ReflectionMethod) {
            return;
        }

        $dropped = [];
        foreach ($modifierAttributes as $class => $label) {
            if ([] !== $this->droppedInstances($overriddenRouteMethod, $method, $class)) {
                $dropped[] = $label;
            }
        }

        if ([] === $dropped) {
            return;
        }

        trigger_error(sprintf('"%s::%s()" overrides "%s::%s()" and repeats #[Route], but drops %s from the parent; %s no longer applies to this route. Repeat it on the override if that was not intended.', $serviceId, $method->getName(), $overriddenRouteMethod->getDeclaringClass()->getName(), $method->getName(), implode(', ', $dropped), implode(', ', $dropped)), E_USER_WARNING);
    }

    private function routeLabel(object $route): string
    {
        assert($route instanceof Route);

        return $route->name ?? $route->path;
    }

    /**
     * The parent's instances of this attribute class that the override does not repeat. A
     * class-based presence check is not enough for a repeatable attribute (#[Route] aliases,
     * OR-combined #[Authenticate]): an override keeping only some instances still "has" the class,
     * while silently dropping the rest. Instances are compared structurally (constructor arguments),
     * not by identity, and each parent instance consumes at most one matching override instance, so
     * duplicates on either side compare correctly.
     *
     * @return list<object>
     */
    private function droppedInstances(ReflectionMethod $parent, ReflectionMethod $override, string $attributeClass): array
    {
        $parentInstances = array_map(static fn ($attribute) => $attribute->newInstance(), $parent->getAttributes($attributeClass));
        if ([] === $parentInstances) {
            return [];
        }

        $overrideInstances = array_map(static fn ($attribute) => $attribute->newInstance(), $override->getAttributes($attributeClass));

        $dropped = [];
        foreach ($parentInstances as $parentInstance) {
            $matchedKey = null;
            foreach ($overrideInstances as $key => $overrideInstance) {
                if (get_object_vars($parentInstance) === get_object_vars($overrideInstance)) {
                    $matchedKey = $key;

                    break;
                }
            }

            if (null === $matchedKey) {
                $dropped[] = $parentInstance;

                continue;
            }

            unset($overrideInstances[$matchedKey]);
        }

        return $dropped;
    }
}
