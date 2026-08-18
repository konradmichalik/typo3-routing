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
use KonradMichalik\Typo3Routing\Attribute\DeprecatedRoute;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * DeprecatedRouteTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(DeprecatedRoute::class)]
final class DeprecatedRouteTest extends TestCase
{
    #[Test]
    public function defaultsToOnlySinceWithNoSunsetSuccessorOrDocumentation(): void
    {
        $deprecation = new DeprecatedRoute(since: '2026-01-01');

        self::assertSame('2026-01-01', $deprecation->since);
        self::assertNull($deprecation->sunset);
        self::assertNull($deprecation->successor);
        self::assertNull($deprecation->documentation);
    }

    #[Test]
    public function storesAllProvidedValues(): void
    {
        $deprecation = new DeprecatedRoute(
            since: '2026-01-01',
            sunset: '2026-12-31',
            successor: 'courses_v2',
            documentation: 'https://example.com/migrate',
        );

        self::assertSame('2026-01-01', $deprecation->since);
        self::assertSame('2026-12-31', $deprecation->sunset);
        self::assertSame('courses_v2', $deprecation->successor);
        self::assertSame('https://example.com/migrate', $deprecation->documentation);
    }

    #[Test]
    public function targetsMethodsAndClassesAndIsNotRepeatable(): void
    {
        $reflection = new ReflectionClass(DeprecatedRoute::class);
        $attribute = $reflection->getAttributes(Attribute::class)[0]->newInstance();

        self::assertSame(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS, $attribute->flags);
    }
}
