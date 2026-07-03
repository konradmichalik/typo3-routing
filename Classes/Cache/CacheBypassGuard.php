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

use KonradMichalik\Typo3Routing\Http\CacheControlBypass;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;

/**
 * CacheBypassGuard.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class CacheBypassGuard
{
    public function __construct(
        private Context $context,
    ) {}

    /**
     * A backend user previewing draft/unpublished content, or a client sending "Cache-Control: no-cache"
     * or "no-store", must never be served a stored response — the cache key does not vary by identity or
     * preview state, so a shared entry would leak between them.
     */
    public function skipsRead(ServerRequestInterface $request): bool
    {
        return $this->isBackendUser() || CacheControlBypass::skipsRead($request);
    }

    /**
     * A backend user's response must never be stored either, since it may carry preview/draft content
     * that a public visitor must not receive on the next cache miss. "no-cache" alone still allows the
     * fresh response to refresh the cache.
     */
    public function skipsWrite(ServerRequestInterface $request): bool
    {
        return $this->isBackendUser() || CacheControlBypass::skipsWrite($request);
    }

    private function isBackendUser(): bool
    {
        // TYPO3 always resolves "backend.user" to a UserAspect, lazily creating a logged-out one when unset.
        return $this->context->getAspect('backend.user')->isLoggedIn();
    }
}
