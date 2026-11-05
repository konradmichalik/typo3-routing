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

use KonradMichalik\Typo3Routing\Attribute\InvalidatesCache;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * InvalidatesCacheTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(InvalidatesCache::class)]
final class InvalidatesCacheTest extends TestCase
{
    #[Test]
    public function storesTheTagsToFlush(): void
    {
        $attribute = new InvalidatesCache(tags: ['tx_news_domain_model_news', 'news_list']);

        self::assertSame(['tx_news_domain_model_news', 'news_list'], $attribute->tags);
    }
}
