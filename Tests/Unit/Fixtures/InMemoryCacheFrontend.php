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

namespace KonradMichalik\Typo3Routing\Tests\Unit\Fixtures;

use BadMethodCallException;
use TYPO3\CMS\Core\Cache\Backend\BackendInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

use function in_array;

/**
 * InMemoryCacheFrontend.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class InMemoryCacheFrontend implements FrontendInterface
{
    /**
     * @var array<string, array{data: mixed, tags: list<string>}>
     */
    private array $entries = [];

    public function getIdentifier(): string
    {
        return 'typo3_routing_test';
    }

    public function getBackend(): BackendInterface
    {
        throw new BadMethodCallException('No backend in the in-memory test frontend.', 1750000002);
    }

    /**
     * @param string       $entryIdentifier
     * @param array<mixed> $tags
     * @param int|null     $lifetime
     */
    public function set($entryIdentifier, mixed $data, array $tags = [], $lifetime = null): void
    {
        /** @var list<string> $tagList */
        $tagList = array_values($tags);
        $this->entries[$entryIdentifier] = ['data' => $data, 'tags' => $tagList];
    }

    /**
     * @param string $entryIdentifier
     */
    public function get($entryIdentifier): mixed
    {
        return $this->entries[$entryIdentifier]['data'] ?? false;
    }

    /**
     * @param string $entryIdentifier
     */
    public function has($entryIdentifier): bool
    {
        return isset($this->entries[$entryIdentifier]);
    }

    /**
     * @param string $entryIdentifier
     */
    public function remove($entryIdentifier): bool
    {
        if (!isset($this->entries[$entryIdentifier])) {
            return false;
        }
        unset($this->entries[$entryIdentifier]);

        return true;
    }

    public function flush(): void
    {
        $this->entries = [];
    }

    /**
     * @param string $tag
     */
    public function flushByTag($tag): void
    {
        foreach ($this->entries as $identifier => $entry) {
            if (in_array($tag, $entry['tags'], true)) {
                unset($this->entries[$identifier]);
            }
        }
    }

    /**
     * @param string[] $tags
     */
    public function flushByTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->flushByTag($tag);
        }
    }

    public function collectGarbage(): void {}

    /**
     * @param string $identifier
     */
    public function isValidEntryIdentifier($identifier): bool
    {
        return true;
    }

    /**
     * @param string $tag
     */
    public function isValidTag($tag): bool
    {
        return true;
    }
}
