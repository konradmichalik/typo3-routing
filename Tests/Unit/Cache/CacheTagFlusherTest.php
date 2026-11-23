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
use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use KonradMichalik\Typo3Routing\Tests\Unit\Fixtures\InMemoryCacheFrontend;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
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
    private const NEWS = 'tx_news_domain_model_news';

    #[Test]
    public function datamapRunFlushesEntriesTaggedWithChangedTable(): void
    {
        $frontend = new InMemoryCacheFrontend();
        $cache = $this->createCache($frontend);
        $cache->store('route_news', new Response('php://temp', 200), 3600, [self::NEWS]);
        $subject = $this->createSubject($cache, [self::NEWS]);
        $dataHandler = $this->createMock(DataHandler::class);

        $subject->processDatamap_afterDatabaseOperations('update', self::NEWS, 1, [], $dataHandler);
        self::assertNotNull($cache->get('route_news'), 'Flushing waits for the end of the run.');

        $subject->processDatamap_afterAllOperations($dataHandler);

        self::assertNull($cache->get('route_news'));
    }

    #[Test]
    public function cmdmapRunFlushesEntriesTaggedWithChangedTable(): void
    {
        $cache = $this->createCache(new InMemoryCacheFrontend());
        $cache->store('route_news', new Response('php://temp', 200), 3600, [self::NEWS]);
        $subject = $this->createSubject($cache, [self::NEWS]);
        $dataHandler = $this->createMock(DataHandler::class);

        $subject->processCmdmap_postProcess('delete', self::NEWS, 1, '', $dataHandler);
        $subject->processCmdmap_afterFinish($dataHandler);

        self::assertNull($cache->get('route_news'));
    }

    #[Test]
    public function flushesEachTagOnceForManyRecordsOfTheRun(): void
    {
        $frontend = $this->createMock(FrontendInterface::class);
        $frontend->expects(self::once())->method('flushByTags')->with([self::NEWS, 'pages']);
        $frontend->expects(self::never())->method('flushByTag');
        $subject = $this->createSubject($this->createCache($frontend), [self::NEWS, 'pages']);
        $dataHandler = $this->createMock(DataHandler::class);

        foreach ([1, 2, 3] as $uid) {
            $subject->processDatamap_afterDatabaseOperations('update', self::NEWS, $uid, [], $dataHandler);
        }
        $subject->processDatamap_afterDatabaseOperations('new', 'pages', 'NEW1', [], $dataHandler);
        $subject->processDatamap_afterAllOperations($dataHandler);
        // The collected tags are cleared after the flush, so a finished run flushes nothing again.
        $subject->processDatamap_afterAllOperations($dataHandler);
    }

    #[Test]
    public function skipsTablesThatAreNoDeclaredCacheTag(): void
    {
        $frontend = $this->createMock(FrontendInterface::class);
        $frontend->expects(self::never())->method('flushByTags');
        $frontend->expects(self::never())->method('flushByTag');
        $subject = $this->createSubject($this->createCache($frontend), [self::NEWS]);
        $dataHandler = $this->createMock(DataHandler::class);

        $subject->processDatamap_afterDatabaseOperations('update', 'tt_content', 1, [], $dataHandler);
        $subject->processDatamap_afterAllOperations($dataHandler);
        $subject->processCmdmap_postProcess('delete', 'tt_content', 1, '', $dataHandler);
        $subject->processCmdmap_afterFinish($dataHandler);
    }

    #[Test]
    public function doesNothingWithoutAnyCachedRoute(): void
    {
        $frontend = $this->createMock(FrontendInterface::class);
        $frontend->expects(self::never())->method('flushByTags');
        $frontend->expects(self::never())->method('flushByTag');
        $subject = $this->createSubject($this->createCache($frontend), []);
        $dataHandler = $this->createMock(DataHandler::class);

        $subject->processDatamap_afterDatabaseOperations('update', 'pages', 1, [], $dataHandler);
        $subject->processDatamap_afterAllOperations($dataHandler);
    }

    /**
     * The flusher is a shared service, so a nested DataHandler (e.g. the one a copy command spawns)
     * must not flush, and thereby clear, the tags its outer run is still collecting.
     */
    #[Test]
    public function keepsTheTagsOfEachDataHandlerRunApart(): void
    {
        $frontend = $this->createMock(FrontendInterface::class);
        $flushed = [];
        $frontend->method('flushByTags')->willReturnCallback(static function (array $tags) use (&$flushed): void {
            $flushed[] = $tags;
        });
        $subject = $this->createSubject($this->createCache($frontend), [self::NEWS, 'pages']);
        $outer = $this->createMock(DataHandler::class);
        $inner = $this->createMock(DataHandler::class);

        $subject->processCmdmap_postProcess('copy', 'pages', 1, 2, $outer);
        $subject->processDatamap_afterDatabaseOperations('new', self::NEWS, 'NEW1', [], $inner);
        $subject->processDatamap_afterAllOperations($inner);
        self::assertSame([[self::NEWS]], $flushed);

        $subject->processCmdmap_afterFinish($outer);
        self::assertSame([[self::NEWS], ['pages']], $flushed);
    }

    private function createCache(FrontendInterface $frontend): ResponseCacheManager
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($frontend);

        return new ResponseCacheManager($cacheManager);
    }

    /**
     * @param list<string> $cacheTags
     */
    private function createSubject(ResponseCacheManager $cache, array $cacheTags): CacheTagFlusher
    {
        return new CacheTagFlusher($cache, new RouteRegistry([], new ServiceLocator([]), cacheTags: $cacheTags));
    }
}
