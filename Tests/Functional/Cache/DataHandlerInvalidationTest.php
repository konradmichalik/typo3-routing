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

namespace KonradMichalik\Typo3Routing\Tests\Functional\Cache;

use KonradMichalik\Typo3Routing\Cache\ResponseCacheManager;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * DataHandlerInvalidationTest.
 *
 * The response cache sits in cache group "pages", so the core DataHandler flushes its `{table}` and
 * `{table}_{uid}` tags itself. These tests run the real DataHandler against the registered cache.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class DataHandlerInvalidationTest extends FunctionalTestCase
{
    private const TABLE = 'tx_routingtest_domain_model_item';

    protected array $testExtensionsToLoad = [
        'typo3_routing',
        __DIR__.'/../Fixtures/Extensions/routing_test',
    ];

    private ResponseCacheManager $responseCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Fixtures/cache_invalidation.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $this->responseCache = $this->get(ResponseCacheManager::class);
    }

    #[Test]
    public function updatingARecordFlushesEntriesTaggedWithItsTableOrItsRecord(): void
    {
        $this->store('route_table', self::TABLE);
        $this->store('route_record', self::TABLE.'_5');
        $this->store('route_other_record', self::TABLE.'_6');
        $this->store('route_unrelated', 'unrelated');

        $this->runDataHandler([self::TABLE => [5 => ['title' => 'Changed']]], []);

        self::assertNull($this->responseCache->get('route_table'));
        self::assertNull($this->responseCache->get('route_record'));
        self::assertNotNull($this->responseCache->get('route_other_record'));
        self::assertNotNull($this->responseCache->get('route_unrelated'));
    }

    #[Test]
    public function deletingARecordFlushesEntriesTaggedWithItsTable(): void
    {
        $this->store('route_table', self::TABLE);

        $this->runDataHandler([], [self::TABLE => [6 => ['delete' => 1]]]);

        self::assertNull($this->responseCache->get('route_table'));
    }

    #[Test]
    public function clearCacheDisableInPageTsConfigSuppressesTheFlush(): void
    {
        $this->store('route_flushed', self::TABLE.'_5');
        $this->store('route_kept', self::TABLE.'_7');

        // Record 5 is the control: if it is not flushed either, the suppression proves nothing.
        $this->runDataHandler([self::TABLE => [5 => ['title' => 'Changed'], 7 => ['title' => 'Changed']]], []);

        self::assertNull($this->responseCache->get('route_flushed'));
        self::assertNotNull($this->responseCache->get('route_kept'));
    }

    #[Test]
    public function clearingThePageCacheGroupFlushesRouteResponses(): void
    {
        $this->store('route_table', self::TABLE);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], []);
        $dataHandler->clear_cacheCmd('pages');

        self::assertNull($this->responseCache->get('route_table'));
    }

    private function store(string $key, string $tag): void
    {
        $this->responseCache->store($key, new Response('php://temp', 200), 3600, [$tag]);
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $data
     * @param array<string, array<int, array<string, mixed>>> $commands
     */
    private function runDataHandler(array $data, array $commands): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, $commands);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();

        self::assertSame([], $dataHandler->errorLog);
    }
}
