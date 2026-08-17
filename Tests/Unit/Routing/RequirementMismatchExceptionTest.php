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

use KonradMichalik\Typo3Routing\Routing\RequirementMismatchException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * RequirementMismatchExceptionTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(RequirementMismatchException::class)]
final class RequirementMismatchExceptionTest extends TestCase
{
    /**
     * Everything that catches a plain miss — the dispatcher's 404 above all — has to keep catching this.
     */
    #[Test]
    public function isAResourceNotFoundException(): void
    {
        self::assertInstanceOf(ResourceNotFoundException::class, $this->exception());
    }

    #[Test]
    public function exposesTheRejectedValueAndItsRoute(): void
    {
        $exception = $this->exception();

        self::assertSame('looseItem', $exception->routeName);
        self::assertSame('code', $exception->parameter);
        self::assertSame('ABC', $exception->value);
        self::assertSame('[a-z]+', $exception->requirement);
    }

    #[Test]
    public function describesTheMismatchInItsMessage(): void
    {
        $message = $this->exception()->getMessage();

        self::assertStringContainsString('looseItem', $message);
        self::assertStringContainsString('code', $message);
        self::assertStringContainsString('ABC', $message);
        self::assertStringContainsString('[a-z]+', $message);
    }

    private function exception(): RequirementMismatchException
    {
        return new RequirementMismatchException('looseItem', 'code', 'ABC', '[a-z]+');
    }
}
