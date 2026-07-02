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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Attribute;

use Attribute;
use KonradMichalik\Typo3Routing\Attribute\Route;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * RouteTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(Route::class)]
final class RouteTest extends TestCase
{
    #[Test]
    public function defaultsToGetMethodAndNullNameAndEnv(): void
    {
        $route = new Route(path: '/api/foo');

        self::assertSame('/api/foo', $route->path);
        self::assertSame(['GET'], $route->methods);
        self::assertNull($route->name);
        self::assertNull($route->env);
        self::assertSame([], $route->requirements);
        self::assertSame(0, $route->priority);
        self::assertSame([], $route->defaults);
    }

    #[Test]
    public function storesAllProvidedValues(): void
    {
        $route = new Route(path: '/api/bar', methods: ['POST', 'PUT'], name: 'bar', env: 'Development', requirements: ['id' => '\d+', 'q' => ''], priority: 10, defaults: ['page' => 1]);

        self::assertSame('/api/bar', $route->path);
        self::assertSame(['POST', 'PUT'], $route->methods);
        self::assertSame('bar', $route->name);
        self::assertSame('Development', $route->env);
        self::assertSame(['id' => '\d+', 'q' => ''], $route->requirements);
        self::assertSame(10, $route->priority);
        self::assertSame(['page' => 1], $route->defaults);
    }

    #[Test]
    public function isRepeatableAndTargetsMethodsAndClasses(): void
    {
        $reflection = new ReflectionClass(Route::class);
        $attribute = $reflection->getAttributes(Attribute::class)[0]->newInstance();

        self::assertSame(
            Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE,
            $attribute->flags,
        );
    }
}
