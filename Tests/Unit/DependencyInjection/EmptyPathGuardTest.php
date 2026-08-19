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

use KonradMichalik\Typo3Routing\DependencyInjection\EmptyPathGuard;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\FixtureController;
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * EmptyPathGuardTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(EmptyPathGuard::class)]
final class EmptyPathGuardTest extends TestCase
{
    #[Test]
    public function doesNothingForANonEmptyPath(): void
    {
        $this->expectNotToPerformAssertions();

        (new EmptyPathGuard())->assertNotEmpty('/api/products', 'products_list', 'products', new ReflectionMethod(FixtureController::class, 'count'));
    }

    #[Test]
    public function throwsForAnEmptyPath(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000032);
        $this->expectExceptionMessage('Route "products_list" (products::count()) resolves to an empty path');

        (new EmptyPathGuard())->assertNotEmpty('', 'products_list', 'products', new ReflectionMethod(FixtureController::class, 'count'));
    }
}
