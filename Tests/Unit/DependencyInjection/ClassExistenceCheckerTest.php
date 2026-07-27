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

use KonradMichalik\Typo3Routing\DependencyInjection\ClassExistenceChecker;
use KonradMichalik\Typo3Routing\Tests\Support\Broken\BrokenParentService;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\FixtureController;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * ClassExistenceCheckerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(ClassExistenceChecker::class)]
final class ClassExistenceCheckerTest extends TestCase
{
    #[Test]
    public function returnsTrueForAnExistingClass(): void
    {
        self::assertTrue((new ClassExistenceChecker())->exists(FixtureController::class));
    }

    #[Test]
    public function returnsFalseForANonExistentClass(): void
    {
        self::assertFalse((new ClassExistenceChecker())->exists(FixtureController::class.'DoesNotExist'));
    }

    #[Test]
    public function returnsFalseInsteadOfCrashingWhenAutoloadingThrowsAnError(): void
    {
        // BrokenParentService extends a class that does not exist; autoloading it throws \Error
        // rather than class_exists() simply returning false.
        self::assertFalse((new ClassExistenceChecker())->exists(BrokenParentService::class));
    }
}
