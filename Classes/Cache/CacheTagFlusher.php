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

namespace KonradMichalik\Typo3Routing\Cache;

use KonradMichalik\Typo3Routing\Routing\RouteRegistry;
use TYPO3\CMS\Core\DataHandling\DataHandler;

use function array_keys;

/**
 * CacheTagFlusher.
 *
 * Collects the changed tables that a #[Cache] route uses as a tag and flushes them once at the end of
 * the DataHandler run.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class CacheTagFlusher
{
    /**
     * @var array<string, true>
     */
    private array $pendingTags = [];

    public function __construct(
        private readonly ResponseCacheManager $cache,
        private readonly RouteRegistry $registry,
    ) {}

    /**
     * Fires after create/update operations.
     *
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, string|int $id, array $fieldArray, DataHandler $dataHandler): void
    {
        $this->collect($table);
    }

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $this->flush($dataHandler);
    }

    /**
     * Fires after command operations (delete, move, copy, …).
     */
    public function processCmdmap_postProcess(string $command, string $table, string|int $id, mixed $value, DataHandler $dataHandler): void
    {
        $this->collect($table);
    }

    public function processCmdmap_afterFinish(DataHandler $dataHandler): void
    {
        $this->flush($dataHandler);
    }

    private function collect(string $table): void
    {
        if ($this->registry->isCacheTag($table)) {
            $this->pendingTags[$table] = true;
        }
    }

    private function flush(DataHandler $dataHandler): void
    {
        // Copy and localize run every record through a nested DataHandler; like core's clear-cache
        // queue, its tags wait for the outermost run.
        if ([] === $this->pendingTags || !$dataHandler->isOuterMostInstance()) {
            return;
        }

        $tags = array_keys($this->pendingTags);
        $this->pendingTags = [];
        $this->cache->flushByTags($tags);
    }
}
