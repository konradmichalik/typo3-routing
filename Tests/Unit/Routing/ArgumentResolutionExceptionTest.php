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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Routing;

use KonradMichalik\Typo3Routing\Routing\ArgumentResolutionException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * ArgumentResolutionExceptionTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(ArgumentResolutionException::class)]
final class ArgumentResolutionExceptionTest extends TestCase
{
    #[Test]
    public function namesTheParameterOfAnInvalidValue(): void
    {
        $exception = ArgumentResolutionException::invalidValue('page', 1234567890);

        self::assertSame('Invalid value for parameter: page', $exception->getMessage());
        self::assertSame(1234567890, $exception->getCode());
    }
}
