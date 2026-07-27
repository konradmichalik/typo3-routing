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
use KonradMichalik\Typo3Routing\Attribute\Cors;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * CorsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(Cors::class)]
final class CorsTest extends TestCase
{
    #[Test]
    public function defaultsToNoCredentialsStandardHeadersAndOneHourMaxAge(): void
    {
        $cors = new Cors(allowedOrigins: ['https://app.example.com']);

        self::assertSame(['https://app.example.com'], $cors->allowedOrigins);
        self::assertFalse($cors->allowCredentials);
        self::assertSame('Content-Type, Authorization', $cors->allowedHeaders);
        self::assertSame('', $cors->exposeHeaders);
        self::assertSame(3600, $cors->maxAge);
    }

    #[Test]
    public function storesAllProvidedValues(): void
    {
        $cors = new Cors(
            allowedOrigins: ['https://partner.example.org'],
            allowCredentials: true,
            allowedHeaders: 'X-Custom',
            exposeHeaders: 'X-Total-Count',
            maxAge: 600,
        );

        self::assertSame(['https://partner.example.org'], $cors->allowedOrigins);
        self::assertTrue($cors->allowCredentials);
        self::assertSame('X-Custom', $cors->allowedHeaders);
        self::assertSame('X-Total-Count', $cors->exposeHeaders);
        self::assertSame(600, $cors->maxAge);
    }

    #[Test]
    public function targetsMethodsAndClassesAndIsNotRepeatable(): void
    {
        $reflection = new ReflectionClass(Cors::class);
        $attribute = $reflection->getAttributes(Attribute::class)[0]->newInstance();

        self::assertSame(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS, $attribute->flags);
    }
}
