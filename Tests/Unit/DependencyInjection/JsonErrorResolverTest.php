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

namespace KonradMichalik\Typo3Routing\Tests\Unit\DependencyInjection;

use KonradMichalik\Typo3Routing\DependencyInjection\{CollectedRoutes, JsonErrorResolver};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * JsonErrorResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(JsonErrorResolver::class)]
final class JsonErrorResolverTest extends TestCase
{
    #[Test]
    public function resolvesABareNonNullableJsonResponseReturnType(): void
    {
        self::assertTrue((new JsonErrorResolver())->resolvesToJsonResponse($this->methodReturning($this->namedType(JsonResponse::class, false))));
    }

    #[Test]
    public function rejectsANullableJsonResponseReturnType(): void
    {
        self::assertFalse((new JsonErrorResolver())->resolvesToJsonResponse($this->methodReturning($this->namedType(JsonResponse::class, true))));
    }

    #[Test]
    public function rejectsADifferentNamedReturnType(): void
    {
        self::assertFalse((new JsonErrorResolver())->resolvesToJsonResponse($this->methodReturning($this->namedType(ResponseInterface::class, false))));
    }

    #[Test]
    public function rejectsAUnionReturnType(): void
    {
        self::assertFalse((new JsonErrorResolver())->resolvesToJsonResponse($this->methodReturning($this->createMock(ReflectionUnionType::class))));
    }

    #[Test]
    public function storesTheFlagOnlyWhenTrue(): void
    {
        $collected = new CollectedRoutes();
        (new JsonErrorResolver())->apply(true, 'flagged', $collected);
        (new JsonErrorResolver())->apply(false, 'plain', $collected);

        self::assertTrue($collected->jsonErrorRoutes['flagged']);
        self::assertArrayNotHasKey('plain', $collected->jsonErrorRoutes);
    }

    #[Test]
    public function rejectsAMethodWithNoDeclaredReturnType(): void
    {
        self::assertFalse((new JsonErrorResolver())->resolvesToJsonResponse($this->methodReturning(null)));
    }

    private function methodReturning(ReflectionNamedType|ReflectionUnionType|null $type): ReflectionMethod
    {
        $method = $this->createMock(ReflectionMethod::class);
        $method->method('getReturnType')->willReturn($type);

        return $method;
    }

    private function namedType(string $name, bool $nullable): ReflectionNamedType
    {
        $type = $this->createMock(ReflectionNamedType::class);
        $type->method('getName')->willReturn($name);
        $type->method('allowsNull')->willReturn($nullable);

        return $type;
    }
}
