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

use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * LanguageReadCountingRequest.
 *
 * Counts reads of the `language` attribute across clones, as a stand-in for "the cache key was built":
 * ResponseCacheManager::buildKey() is the one cache-side reader of it.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class LanguageReadCountingRequest extends ServerRequest
{
    public static int $languageReads = 0;

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        if ('language' === $name) {
            ++self::$languageReads;
        }

        return parent::getAttribute($name, $default);
    }
}
