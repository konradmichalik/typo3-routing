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

use ReflectionMethod;

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

    public function warnIfRouteWasDropped(?ReflectionMethod $overriddenRouteMethod, ReflectionMethod $method, string $serviceId): void
    {
        if (!$overriddenRouteMethod instanceof ReflectionMethod) {
            return;
        }

        trigger_error(sprintf('"%s::%s()" overrides "%s::%s()", which declares a #[Route], but the override does not repeat it; the inherited route no longer exists. Repeat the #[Route] (and any modifier attributes) on the override, or remove the override if no change was intended.', $serviceId, $method->getName(), $overriddenRouteMethod->getDeclaringClass()->getName(), $method->getName()), E_USER_WARNING);
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
            if ([] !== $overriddenRouteMethod->getAttributes($class) && [] === $method->getAttributes($class)) {
                $dropped[] = $label;
            }
        }

        if ([] === $dropped) {
            return;
        }

        trigger_error(sprintf('"%s::%s()" overrides "%s::%s()" and repeats #[Route], but drops %s from the parent; %s no longer applies to this route. Repeat it on the override if that was not intended.', $serviceId, $method->getName(), $overriddenRouteMethod->getDeclaringClass()->getName(), $method->getName(), implode(', ', $dropped), implode(', ', $dropped)), E_USER_WARNING);
    }
}
