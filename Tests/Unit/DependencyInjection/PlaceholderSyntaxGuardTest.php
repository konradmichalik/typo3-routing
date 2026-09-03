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

use KonradMichalik\Typo3Routing\DependencyInjection\PlaceholderSyntaxGuard;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\FixtureController;
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * PlaceholderSyntaxGuardTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(PlaceholderSyntaxGuard::class)]
final class PlaceholderSyntaxGuardTest extends TestCase
{
    #[Test]
    public function doesNothingForPlainPlaceholders(): void
    {
        $this->expectNotToPerformAssertions();

        (new PlaceholderSyntaxGuard())->assertSupported('/api/products/{id}', 'products_show', 'products', new ReflectionMethod(FixtureController::class, 'count'));
    }

    #[Test]
    public function throwsForAnInlineRequirement(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000039);
        $this->expectExceptionMessage('Route "products_show" (products::count()) uses unsupported placeholder syntax in path "/api/products/{id<\d+>}": "{id<\d+>}"');

        (new PlaceholderSyntaxGuard())->assertSupported('/api/products/{id<\d+>}', 'products_show', 'products', new ReflectionMethod(FixtureController::class, 'count'));
    }

    #[Test]
    public function namesEveryOffender(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000039);
        $this->expectExceptionMessage('"{!a}", "{b<\d+>}"');

        (new PlaceholderSyntaxGuard())->assertSupported('/api/{!a}/{b<\d+>}', 'products_show', 'products', new ReflectionMethod(FixtureController::class, 'count'));
    }
}
