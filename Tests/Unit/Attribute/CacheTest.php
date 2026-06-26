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

use KonradMichalik\Typo3Routing\Attribute\Cache;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * CacheTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(Cache::class)]
final class CacheTest extends TestCase
{
    #[Test]
    public function defaultsToOneDayLifetimeAndNoTags(): void
    {
        $cache = new Cache();

        self::assertSame(86400, $cache->lifetime);
        self::assertSame([], $cache->tags);
        self::assertSame([], $cache->ignoreParams);
    }

    #[Test]
    public function storesAllProvidedValues(): void
    {
        $cache = new Cache(lifetime: 3600, tags: ['tx_news_domain_model_news'], ignoreParams: ['search']);

        self::assertSame(3600, $cache->lifetime);
        self::assertSame(['tx_news_domain_model_news'], $cache->tags);
        self::assertSame(['search'], $cache->ignoreParams);
    }
}
