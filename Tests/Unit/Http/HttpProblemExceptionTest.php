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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Http;

use KonradMichalik\Typo3Routing\Http\HttpProblemException;
use LogicException;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * HttpProblemExceptionTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(HttpProblemException::class)]
final class HttpProblemExceptionTest extends TestCase
{
    #[Test]
    public function exposesStatusAndDetail(): void
    {
        $exception = new HttpProblemException(409, 'Item already processed');

        self::assertSame(409, $exception->status);
        self::assertSame('Item already processed', $exception->getMessage());
    }

    #[Test]
    public function acceptsTheFullErrorStatusRange(): void
    {
        self::assertSame(400, (new HttpProblemException(400))->status);
        self::assertSame(599, (new HttpProblemException(599))->status);
    }

    #[Test]
    public function rejectsASuccessStatusCode(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000023);

        new HttpProblemException(200);
    }

    #[Test]
    public function rejectsAStatusCodeAboveTheErrorRange(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1750000023);

        new HttpProblemException(600);
    }
}
