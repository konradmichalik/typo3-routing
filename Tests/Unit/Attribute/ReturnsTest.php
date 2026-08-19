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

use KonradMichalik\Typo3Routing\Attribute\Returns;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\Dto\CourseDto;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * ReturnsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(Returns::class)]
final class ReturnsTest extends TestCase
{
    #[Test]
    public function defaultsToStatus200NoCollectionAndNoDescription(): void
    {
        $returns = new Returns();

        self::assertNull($returns->schema);
        self::assertSame(200, $returns->status);
        self::assertFalse($returns->collection);
        self::assertNull($returns->description);
    }

    #[Test]
    public function storesAllProvidedValues(): void
    {
        $returns = new Returns(CourseDto::class, status: 201, collection: true, description: 'Created course');

        self::assertSame(CourseDto::class, $returns->schema);
        self::assertSame(201, $returns->status);
        self::assertTrue($returns->collection);
        self::assertSame('Created course', $returns->description);
    }
}
