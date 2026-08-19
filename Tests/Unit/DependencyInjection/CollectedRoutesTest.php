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

use KonradMichalik\Typo3Routing\DependencyInjection\CollectedRoutes;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * CollectedRoutesTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(CollectedRoutes::class)]
final class CollectedRoutesTest extends TestCase
{
    #[Test]
    public function recordClassExclusivePrefixDoesNothingForNull(): void
    {
        $collected = new CollectedRoutes();

        $collected->recordClassExclusivePrefix(null);

        self::assertSame([], $collected->classExclusivePrefixes);
    }

    #[Test]
    public function recordClassExclusivePrefixAppendsANonNullPrefix(): void
    {
        $collected = new CollectedRoutes();

        $collected->recordClassExclusivePrefix('/api/products');
        $collected->recordClassExclusivePrefix('/api/orders');

        self::assertSame(['/api/products', '/api/orders'], $collected->classExclusivePrefixes);
    }
}
