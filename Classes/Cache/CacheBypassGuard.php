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

use TYPO3\CMS\Core\Context\Context;

/**
 * CacheBypassGuard.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class CacheBypassGuard
{
    public function __construct(
        private Context $context,
    ) {}

    /**
     * A logged-in backend user previewing draft/unpublished content neither reads nor writes the cache: the
     * key does not vary by preview state, so a stored entry would leak between editors and public visitors.
     */
    public function isBypassed(): bool
    {
        // TYPO3 always resolves "backend.user" to a UserAspect, lazily creating a logged-out one when unset.
        return $this->context->getAspect('backend.user')->isLoggedIn();
    }
}
