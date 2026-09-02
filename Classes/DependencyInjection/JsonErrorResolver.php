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
use ReflectionNamedType;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * JsonErrorResolver.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class JsonErrorResolver
{
    /**
     * Whether the controller method's declared return type is a bare, non-nullable JsonResponse —
     * the signal ControllerInvoker uses to convert an otherwise-uncaught exception into a generic
     * JSON error instead of letting it reach TYPO3's regular (HTML) error handling. Deliberately
     * narrow: a nullable or union return type means the method can answer with something other than
     * JSON, so guessing "JSON errors" for it would be wrong as often as it is right.
     */
    public function resolvesToJsonResponse(ReflectionMethod $method): bool
    {
        $type = $method->getReturnType();

        return $type instanceof ReflectionNamedType && !$type->allowsNull() && JsonResponse::class === $type->getName();
    }

    /**
     * Stores the resolved flag into the collected route metadata; a no-op when the method's return
     * type did not resolve to a bare JsonResponse.
     */
    public function apply(bool $jsonErrors, string $name, CollectedRoutes $collected): void
    {
        if ($jsonErrors) {
            $collected->jsonErrorRoutes[$name] = true;
        }
    }
}
