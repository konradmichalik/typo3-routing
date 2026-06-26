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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Cache;

use KonradMichalik\Typo3Routing\Cache\{CacheTagFlusher, ResponseCacheManager};
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\CreatesResponseCacheManager;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\Response;

/**
 * CacheTagFlusherTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(CacheTagFlusher::class)]
final class CacheTagFlusherTest extends TestCase
{
    use CreatesResponseCacheManager;

    private ResponseCacheManager $cache;
    private CacheTagFlusher $subject;

    protected function setUp(): void
    {
        $this->cache = $this->createResponseCacheManager();
        $this->subject = new CacheTagFlusher($this->cache);
    }

    #[Test]
    public function datamapOperationFlushesEntriesTaggedWithChangedTable(): void
    {
        $this->cache->store('route_news', new Response('php://temp', 200), 3600, ['tx_news_domain_model_news']);
        self::assertNotNull($this->cache->get('route_news'));

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tx_news_domain_model_news',
            1,
            [],
            $this->createMock(DataHandler::class),
        );

        self::assertNull($this->cache->get('route_news'));
    }

    #[Test]
    public function cmdmapOperationFlushesEntriesTaggedWithChangedTable(): void
    {
        $this->cache->store('route_news', new Response('php://temp', 200), 3600, ['tx_news_domain_model_news']);
        self::assertNotNull($this->cache->get('route_news'));

        $this->subject->processCmdmap_postProcess(
            'delete',
            'tx_news_domain_model_news',
            1,
            '',
            $this->createMock(DataHandler::class),
        );

        self::assertNull($this->cache->get('route_news'));
    }

    #[Test]
    public function leavesEntriesOfUnrelatedTablesUntouched(): void
    {
        $this->cache->store('route_news', new Response('php://temp', 200), 3600, ['tx_news_domain_model_news']);

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tt_content',
            1,
            [],
            $this->createMock(DataHandler::class),
        );

        self::assertNotNull($this->cache->get('route_news'));
    }
}
