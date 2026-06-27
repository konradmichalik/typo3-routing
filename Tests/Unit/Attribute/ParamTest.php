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
use KonradMichalik\Typo3Routing\Attribute\Param;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ParamTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(Param::class)]
final class ParamTest extends TestCase
{
    #[Test]
    public function defaultsToNullNameAndSource(): void
    {
        $param = new Param();

        self::assertNull($param->name);
        self::assertNull($param->source);
    }

    #[Test]
    public function storesProvidedValues(): void
    {
        $param = new Param(name: 'q', source: 'query');

        self::assertSame('q', $param->name);
        self::assertSame('query', $param->source);
    }

    #[Test]
    public function targetsParameters(): void
    {
        $reflection = new ReflectionClass(Param::class);
        $attribute = $reflection->getAttributes(Attribute::class)[0]->newInstance();

        self::assertSame(Attribute::TARGET_PARAMETER, $attribute->flags);
    }
}
