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

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * CacheTagFlusher.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class CacheTagFlusher
{
    public function __construct(
        private ResponseCacheManager $cache,
    ) {}

    /**
     * Fires after create/update operations.
     *
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, string|int $id, array $fieldArray, DataHandler $dataHandler): void
    {
        $this->cache->flushByTag($table);
    }

    /**
     * Fires after command operations (delete, move, copy, …).
     */
    public function processCmdmap_postProcess(string $command, string $table, string|int $id, mixed $value, DataHandler $dataHandler): void
    {
        $this->cache->flushByTag($table);
    }
}
