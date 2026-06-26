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

namespace KonradMichalik\Typo3Routing\Tests\Unit\RateLimit;

use KonradMichalik\Typo3Routing\RateLimit\CacheStorage;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\InMemoryCacheFrontend;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\Policy\Window;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * CacheStorageTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(CacheStorage::class)]
final class CacheStorageTest extends TestCase
{
    #[Test]
    public function savesAndFetchesLimiterStateById(): void
    {
        $storage = new CacheStorage(new InMemoryCacheFrontend());
        $state = new Window('client-7', 60, 10);

        $storage->save($state);

        $fetched = $storage->fetch('client-7');
        self::assertInstanceOf(Window::class, $fetched);
        self::assertSame('client-7', $fetched->getId());
    }

    #[Test]
    public function fetchReturnsNullForUnknownId(): void
    {
        $storage = new CacheStorage(new InMemoryCacheFrontend());

        self::assertNull($storage->fetch('never-seen'));
    }

    #[Test]
    public function deleteRemovesState(): void
    {
        $storage = new CacheStorage(new InMemoryCacheFrontend());
        $storage->save(new Window('client-7', 60, 10));

        $storage->delete('client-7');

        self::assertNull($storage->fetch('client-7'));
    }

    #[Test]
    public function persistsUsingTheStateExpirationTimeAsCacheLifetime(): void
    {
        $state = new Window('client-7', 60, 10);
        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::once())->method('set')->with(
            self::isString(),
            self::anything(),
            [],
            $state->getExpirationTime(),
        );

        (new CacheStorage($cache))->save($state);
    }
}
